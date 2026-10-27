<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, Category $category): bool
    {
        return (int) $user->id === (int) $category->user_id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, Category $category): bool
    {
        return (int) $user->id === (int) $category->user_id;
    }

    public function archive(User $user, Category $category): bool
    {
        return (int) $user->id === (int) $category->user_id;
    }

    public function restore(User $user, Category $category): bool
    {
        return (int) $user->id === (int) $category->user_id;
    }

    public function delete(): bool
    {
        return false;
    }
}
