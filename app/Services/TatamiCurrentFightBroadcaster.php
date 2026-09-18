<?php

namespace App\Services;

use App\Models\Pool;

class TatamiCurrentFightBroadcaster
{
    public function broadcastForPool(Pool $pool): void
    {
        // В старой версии это обновляло текущий бой на татами через sockets.
        // Сейчас оставляем безопасный no-op, чтобы сетка, победы и неявки
        // работали независимо от будущего realtime-слоя.
    }
}
