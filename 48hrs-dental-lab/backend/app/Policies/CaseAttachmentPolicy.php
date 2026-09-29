<?php

namespace App\Policies;

use App\Domain\Permissions;
use App\Models\CaseAttachment;
use App\Models\User;

class CaseAttachmentPolicy
{
    /** Uploaders may remove their own files; anyone else needs files.delete. */
    public function delete(User $user, CaseAttachment $file): bool
    {
        return ($file->uploaded_by_id === $user->id && $user->hasPermission(Permissions::FILES_UPLOAD)) || $user->hasPermission(Permissions::FILES_DELETE);
    }
}
