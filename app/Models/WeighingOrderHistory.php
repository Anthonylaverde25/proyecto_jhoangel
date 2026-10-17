<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeighingOrderHistory extends Model
{
    protected $fillable = ['company_id', 'weighing_order_id', 'from_status', 'to_status', 'user_id', 'notes'];
}
