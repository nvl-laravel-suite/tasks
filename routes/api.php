<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Tasks\Http\Controllers\TasksManagementController;
use Nvl\Tasks\Http\Controllers\TasksManagementExtrasController;
use Nvl\Tasks\Support\TasksRouteConfiguration;

if (config('nvl-tasks.routes.management.enabled', false) === true) {
    Route::prefix(TasksRouteConfiguration::path())
        ->name(TasksRouteConfiguration::name())
        ->middleware(TasksRouteConfiguration::middleware())
        ->group(function (): void {
            Route::get('/', [TasksManagementController::class, 'index'])->name('index');
            Route::get('/dashboard', [TasksManagementExtrasController::class, 'dashboard'])->name('dashboard');
            Route::post('/', [TasksManagementController::class, 'store'])->name('store');
            Route::get('/{task}/detail', [TasksManagementExtrasController::class, 'detail'])
                ->whereUuid('task')->name('detail');
            Route::get('/{task}/checklist-items', [TasksManagementExtrasController::class, 'checklistItems'])
                ->whereUuid('task')->name('checklist.index');
            Route::post('/{task}/checklist-items', [TasksManagementExtrasController::class, 'addChecklistItem'])
                ->whereUuid('task')->name('checklist.store');
            Route::put('/{task}/checklist-items/order', [TasksManagementExtrasController::class, 'reorderChecklistItems'])
                ->whereUuid('task')->name('checklist.reorder');
            Route::put('/{task}/checklist-items/{item}', [TasksManagementExtrasController::class, 'updateChecklistItem'])
                ->whereUuid('task')->whereUuid('item')->name('checklist.update');
            Route::post('/{task}/checklist-items/{item}/completion', [TasksManagementExtrasController::class, 'toggleChecklistItem'])
                ->whereUuid('task')->whereUuid('item')->name('checklist.completion');
            Route::delete('/{task}/checklist-items/{item}', [TasksManagementExtrasController::class, 'removeChecklistItem'])
                ->whereUuid('task')->whereUuid('item')->name('checklist.destroy');
            Route::post('/{task}/tags', [TasksManagementExtrasController::class, 'addTag'])
                ->whereUuid('task')->name('tags.store');
            Route::delete('/{task}/tags/{tag}', [TasksManagementExtrasController::class, 'removeTag'])
                ->whereUuid('task')->name('tags.destroy');
            Route::post('/{task}/time-entries', [TasksManagementExtrasController::class, 'addTimeEntry'])
                ->whereUuid('task')->name('time-entries.store');
            Route::get('/{task}/time-entries', [TasksManagementExtrasController::class, 'timeEntries'])
                ->whereUuid('task')->name('time-entries.index');
            Route::put('/{task}/time-entries/{entry}', [TasksManagementExtrasController::class, 'updateTimeEntry'])
                ->whereUuid('task')->whereUuid('entry')->name('time-entries.update');
            Route::delete('/{task}/time-entries/{entry}', [TasksManagementExtrasController::class, 'deleteTimeEntry'])
                ->whereUuid('task')->whereUuid('entry')->name('time-entries.destroy');
            Route::post('/{task}/timer', [TasksManagementExtrasController::class, 'startTimer'])
                ->whereUuid('task')->name('timer.start');
            Route::post('/{task}/timer/{entry}/stop', [TasksManagementExtrasController::class, 'stopTimer'])
                ->whereUuid('task')->whereUuid('entry')->name('timer.stop');
            Route::put('/{task}/parent/{related}', [TasksManagementExtrasController::class, 'linkParent'])
                ->whereUuid('task')->whereUuid('related')->name('parent.store');
            Route::delete('/{task}/parent/{related}', [TasksManagementExtrasController::class, 'unlinkParent'])
                ->whereUuid('task')->whereUuid('related')->name('parent.destroy');
            Route::post('/{task}/blockers/{related}', [TasksManagementExtrasController::class, 'addBlocker'])
                ->whereUuid('task')->whereUuid('related')->name('blockers.store');
            Route::delete('/{task}/blockers/{related}', [TasksManagementExtrasController::class, 'removeBlocker'])
                ->whereUuid('task')->whereUuid('related')->name('blockers.destroy');
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
