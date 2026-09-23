<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Tasks\Http\Controllers\TasksManagementController;
use Nvl\Tasks\Support\TasksRouteConfiguration;

if (config('tasks.routes.management.enabled', false) === true) {
    Route::prefix(TasksRouteConfiguration::path())
        ->name(TasksRouteConfiguration::name())
        ->middleware(TasksRouteConfiguration::middleware())
        ->group(function (): void {
            Route::get('/', [TasksManagementController::class, 'index'])->name('index');
            Route::post('/', [TasksManagementController::class, 'store'])->name('store');
            Route::get('/{task}', [TasksManagementController::class, 'show'])->whereUuid('task')->name('show');
            Route::put('/{task}', [TasksManagementController::class, 'update'])->whereUuid('task')->name('update');
            Route::delete('/{task}', [TasksManagementController::class, 'destroy'])->whereUuid('task')->name('destroy');
            Route::post('/{task}/restore', [TasksManagementController::class, 'restore'])
                ->whereUuid('task')->name('restore');
            Route::post('/{task}/assignees', [TasksManagementController::class, 'assign'])
                ->whereUuid('task')->name('assignees.store');
            Route::delete('/{task}/assignees/{assignee}', [TasksManagementController::class, 'unassign'])
                ->whereUuid('task')->name('assignees.destroy');
        });
}
