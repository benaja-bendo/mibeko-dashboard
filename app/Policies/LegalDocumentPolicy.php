<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LegalDocument;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LegalDocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LegalDocument');
    }

    public function view(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->can('View:LegalDocument');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('documents.create') || $authUser->can('Create:LegalDocument');
    }

    public function update(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->can('documents.update') || $authUser->can('Update:LegalDocument');
    }

    public function delete(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->can('documents.delete') || $authUser->can('Delete:LegalDocument');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('documents.delete') || $authUser->can('DeleteAny:LegalDocument');
    }

    public function restore(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->can('documents.delete') || $authUser->can('Restore:LegalDocument');
    }

    public function forceDelete(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->hasRole('admin') || $authUser->can('ForceDelete:LegalDocument');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->hasRole('admin') || $authUser->can('ForceDeleteAny:LegalDocument');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LegalDocument');
    }

    public function replicate(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->can('Replicate:LegalDocument');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LegalDocument');
    }
}
