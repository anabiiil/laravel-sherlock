<?php

declare(strict_types=1);

/*
| Dashboard routes. Loaded by DashboardServiceProvider inside a group that applies the
| configured domain / path / middleware + the viewSherlock gate and the "sherlock." name prefix.
*/

use Anabil\Sherlock\Http\Controllers\IssuesController;
use Anabil\Sherlock\Http\Controllers\OverviewController;
use Anabil\Sherlock\Http\Controllers\QueriesController;
use Anabil\Sherlock\Http\Controllers\TracesController;
use Illuminate\Support\Facades\Route;

$id = '[A-Za-z0-9_-]+';

Route::get('/', OverviewController::class)->name('overview');

Route::get('issues', [IssuesController::class, 'index'])->name('issues.index');
Route::get('issues/{issue}', [IssuesController::class, 'show'])->name('issues.show')->where('issue', $id);
Route::post('issues/{issue}/resolve', [IssuesController::class, 'resolve'])->name('issues.resolve')->where('issue', $id);
Route::post('issues/{issue}/ignore', [IssuesController::class, 'ignore'])->name('issues.ignore')->where('issue', $id);
Route::post('issues/{issue}/reopen', [IssuesController::class, 'reopen'])->name('issues.reopen')->where('issue', $id);
Route::post('issues/{issue}/reanalyze', [IssuesController::class, 'reanalyze'])->name('issues.reanalyze')->where('issue', $id);

Route::get('traces', [TracesController::class, 'index'])->name('traces.index');
Route::get('traces/{trace}', [TracesController::class, 'show'])->name('traces.show')->where('trace', $id);

Route::get('queries', QueriesController::class)->name('queries.index');
