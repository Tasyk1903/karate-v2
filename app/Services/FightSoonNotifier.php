<?php

namespace App\Services;

use App\Models\Pool;

class FightSoonNotifier
{
    public function handlePoolChanged(Pool $pool): void
    {
        // В v2 пока не включаем push-уведомления о боях. Метод оставлен как
        // стабильная точка расширения, чтобы спортивная логика сетки не зависела
        // от Reverb/web-push инфраструктуры.
    }
}
