<?php

namespace App\Observers;

use App\Models\Numeraha;
use Filament\Notifications\Notification;

class NumerahObserver
{
    /**
     * Handle the Numeraha "created" event.
     */
    public function created(Numeraha $numeraha): void
    {
        $this->notifyStaff('نمره (ځمکه) اضافه شوه');
    }

    /**
     * Handle the Numeraha "updated" event.
     */
    public function updated(Numeraha $numeraha): void
    {
        $this->notifyStaff('نمره (ځمکه) تازه شوه');
    }

    /**
     * Handle the Numeraha "deleted" event.
     */
    public function deleted(Numeraha $numeraha): void
    {
        //
    }

    /**
     * Handle the Numeraha "restored" event.
     */
    public function restored(Numeraha $numeraha): void
    {
        //
    }

    /**
     * Handle the Numeraha "force deleted" event.
     */
    public function forceDeleted(Numeraha $numeraha): void
    {
        //
    }

    private function notifyStaff(string $title): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title($title)
            ->sendToDatabase($user);
    }
}
