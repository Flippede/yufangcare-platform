<?php

namespace app\model\yfth;

use crmeb\traits\ModelTrait;

class YfthIdempotencyRecord extends YfthBaseModel
{
    use ModelTrait;

    protected $name = 'yfth_idempotency_record';
}
