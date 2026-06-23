<?php
namespace app\services\product;

use app\model\product\ProductLevelPrice;

class LevelPriceServices
{
    /** 整商品/指定SKU的经销商价（有SKU优先） */
    public function getLevelPriceForSku(int $productId, int $levelId, ?string $skuUnique): ?array
    {
        if ($skuUnique) {
            $row = ProductLevelPrice::where('product_id',$productId)
                ->where('level_id',$levelId)
                ->where('sku_unique',$skuUnique)
                ->where('status',1)->find();
            if ($row) return $row->toArray();
        }
        return $this->getLevelPrice($productId, $levelId, null);
    }

    /** 整商品经销商价 */
    public function getLevelPrice(int $productId, int $levelId, ?string $skuUnique=null): ?array
    {
        $q = ProductLevelPrice::where('product_id',$productId)->where('level_id',$levelId);
        if ($skuUnique !== null) $q->where('sku_unique',$skuUnique);
        $row = $q->where('status',1)->find();
        return $row ? $row->toArray() : null;
    }
}
