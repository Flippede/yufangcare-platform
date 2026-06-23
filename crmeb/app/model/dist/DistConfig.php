<?php
namespace app\model\dist;

use crmeb\basic\BaseModel;

class DistConfig extends BaseModel
{
    protected $name = 'eb_dist_config';

    public static function getv(string $key, $default=null){
        $val = self::where('key',$key)->value('value');
        return $val !== null ? $val : $default;
    }
}
