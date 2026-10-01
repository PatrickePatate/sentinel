<?php

use Illuminate\Support\Facades\Broadcast;

// One private channel for the dashboard: only admins may listen. Events carry no data, only "something changed".
Broadcast::channel('sentinel', fn ($user) => (bool) $user->is_admin);
