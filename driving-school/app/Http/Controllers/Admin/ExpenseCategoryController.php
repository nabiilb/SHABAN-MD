<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        return view('admin.expense-categories.index', [
            'categories' => ExpenseCategory::withCount('expenses')->orderBy('sort_order')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ExpenseCategory::class);

        return view('admin.expense-categories.form', ['category' => new ExpenseCategory(['is_active' => true])]);
    }

    public function store(ExpenseCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', ExpenseCategory::class);

        $category = ExpenseCategory::create($request->validated());
        AuditLogger::created($category, "Expense category {$category->name} added");

        return redirect()->route('admin.expense-categories.index')->with('status', __('Category added.'));
    }

    public function edit(ExpenseCategory $category): View
    {
        $this->authorize('update', $category);

        return view('admin.expense-categories.form', ['category' => $category]);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $original = $category->getOriginal();
        $category->update($request->validated());
        AuditLogger::updated($category, "Expense category {$category->name} updated", $original);

        return redirect()->route('admin.expense-categories.index')->with('status', __('Category updated.'));
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        if ($category->expenses()->exists()) {
            return back()->withErrors(['category' => __('This category is in use and cannot be deleted. Deactivate it instead.')]);
        }

        $name = $category->name;
        $category->delete();
        AuditLogger::log('expensecategory.deleted', $category, "Expense category {$name} removed");

        return back()->with('status', __('Category removed.'));
    }
}
