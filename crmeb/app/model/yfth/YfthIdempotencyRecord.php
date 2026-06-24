<?php

namespace app\model\yfth;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

class YfthIdempotencyRecord extends BaseModel
{
    use ModelTrait;

    protected $name = 'yfth_idempotency_record';
}
