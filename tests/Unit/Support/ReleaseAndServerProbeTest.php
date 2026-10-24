<?php

declare(strict_types=1);

use Anabil\Sherlock\Support\ReleaseDetector;
use Anabil\Sherlock\Support\SystemServerProbe;

function sherlockTempDir(): string
{
    $dir = sys_get_temp_dir().'/sherlock-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function sherlockRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function sherlockWrite(string $path, string $contents): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

const SHA = '3f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6c5b4a39';

beforeEach(function (): void {
    ReleaseDetector::flush();
    SystemServerProbe::flush();
    $this->dir = sherlockTempDir();
});

afterEach(function (): void {
    sherlockRemoveDir($this->dir);
    ReleaseDetector::flush();
    SystemServerProbe::flush();
});

describe('ReleaseDetector', function (): void {
    it('reads the branch ref of .git/HEAD as a short SHA', function (): void {
        sherlockWrite($this->dir.'/.git/HEAD', "ref: refs/heads/main\n");
        sherlockWrite($this->dir.'/.git/refs/heads/main', SHA."\n");

        expect(ReleaseDetector::detect($this->dir))->toBe('3f2a9c1');
    });

    it('falls back to packed-refs', function (): void {
        sherlockWrite($this->dir.'/.git/HEAD', "ref: refs/heads/release/2.0\n");
        sherlockWrite($this->dir.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n"
            ."aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n"
            .SHA." refs/heads/release/2.0\n"
            ."^bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n");

        expect(ReleaseDetector::detect($this->dir))->toBe('3f2a9c1');
    });

    it('uses a detached HEAD as is', function (): void {
        sherlockWrite($this->dir.'/.git/HEAD', SHA."\n");

        expect(ReleaseDetector::detect($this->dir))->toBe('3f2a9c1');
    });

    it('follows a worktree .git file and its common dir', function (): void {
        $main = $this->dir.'/main/.git';
        sherlockWrite($main.'/refs/heads/feature', SHA);
        sherlockWrite($main.'/worktrees/wt/HEAD', 'ref: refs/heads/feature');
        sherlockWrite($main.'/worktrees/wt/commondir', '../..');
        sherlockWrite($this->dir.'/wt/.git', 'gitdir: '.$main.'/worktrees/wt');

        expect(ReleaseDetector::detect($this->dir.'/wt'))->toBe('3f2a9c1');
    });

    it('prefers a .release / REVISION file written by the deploy', function (): void {
        sherlockWrite($this->dir.'/.git/HEAD', SHA);
        sherlockWrite($this->dir.'/REVISION', SHA."\nextra line");

        expect(ReleaseDetector::detect($this->dir))->toBe('3f2a9c1');

        ReleaseDetector::flush();
        sherlockWrite($this->dir.'/.release', "v2.4.1 <script>\n");

        expect(ReleaseDetector::detect($this->dir))->toBe('v2.4.1script');
    });

    it('returns null without any source, and memoizes per process', function (): void {
        expect(ReleaseDetector::detect($this->dir))->toBeNull()
            ->and(ReleaseDetector::detect(null))->toBeNull();

        sherlockWrite($this->dir.'/.release', 'v9');

        // Memoized: no file read until flushed (config:cache bakes the value anyway).
        expect(ReleaseDetector::detect($this->dir))->toBeNull();

        ReleaseDetector::flush();

        expect(ReleaseDetector::detect($this->dir))->toBe('v9');
    });

    it('ignores a malformed HEAD and ref paths escaping the git dir', function (): void {
        sherlockWrite($this->dir.'/.git/HEAD', 'ref: ../../etc/passwd');

        expect(ReleaseDetector::detect($this->dir))->toBeNull();
    });

    it('normalizes configured values (the env override)', function (): void {
        expect(ReleaseDetector::normalize(' 2026.10.24-1 '))->toBe('2026.10.24-1')
            ->and(ReleaseDetector::normalize(''))->toBeNull()
            ->and(ReleaseDetector::normalize(null))->toBeNull()
            ->and(ReleaseDetector::normalize(42))->toBe('42')
            ->and(strlen((string) ReleaseDetector::normalize(str_repeat('a', 100))))->toBe(64);
    });
});

describe('SystemServerProbe', function (): void {
    it('counts cores from cpuinfo once per process and reads MemAvailable', function (): void {
        sherlockWrite($this->dir.'/cpuinfo', "processor\t: 0\nmodel name\t: x\n\nprocessor\t: 1\n\nprocessor\t: 2\n");
        sherlockWrite($this->dir.'/meminfo', "MemTotal:       16384000 kB\nMemFree:  100 kB\nMemAvailable:    2097152 kB\n");

        $probe = new SystemServerProbe(procPath: $this->dir);

        expect($probe->cpuCores())->toBe(3)
            ->and($probe->memoryAvailableMb())->toBe(2048);

        unlink($this->dir.'/cpuinfo');

        expect($probe->cpuCores())->toBe(3)
            ->and((new SystemServerProbe(procPath: $this->dir))->cpuCores())->toBe(3);
    });

    it('lets config override the cores and disable signals', function (): void {
        $probe = new SystemServerProbe(false, false, false, 8, $this->dir);

        expect($probe->cpuCores())->toBe(8)
            ->and($probe->cpuTimeMs())->toBeNull()
            ->and($probe->loadAverage())->toBeNull()
            ->and($probe->memoryAvailableMb())->toBeNull();
    });

    it('returns null where the platform has no /proc', function (): void {
        $probe = new SystemServerProbe(procPath: $this->dir.'/missing');

        expect($probe->cpuCores())->toBeNull()
            ->and($probe->memoryAvailableMb())->toBeNull();
    });

    it('measures CPU time and the load average of the running host', function (): void {
        $probe = new SystemServerProbe;
        $before = $probe->cpuTimeMs();
        $x = 0;
        for ($i = 0; $i < 200000; $i++) {
            $x += $i % 7;
        }
        $after = $probe->cpuTimeMs();

        expect($before)->toBeFloat()
            ->and($after)->toBeGreaterThanOrEqual($before)
            ->and($probe->loadAverage())->toBeGreaterThanOrEqual(0.0);
    });
});
