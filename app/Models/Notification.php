<?php

// app/Models/Notification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    protected $fillable = ['title', 'body', 'target_roles'];

    protected $casts = [
        'target_roles' => 'array', // json casting
    ];

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }
}
