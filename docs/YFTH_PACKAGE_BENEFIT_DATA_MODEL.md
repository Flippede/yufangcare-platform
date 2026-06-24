# 御方通和 5980 套餐与权益数据模型

## 1. 表清单

| 表 | 作用 |
| --- | --- |
| `yfth_package_template` | 套餐模板，保存套餐编码、名称、基础价格、权益月数、协议正文和发布状态。 |
| `yfth_package_rule_version` | 套餐规则版本，保存价格、月数、权益规则快照、协议摘要和 hash。 |
| `yfth_package_product_binding` | 套餐规则与 CRMEB 商品/SKU 绑定，保存商品、SKU 和价格快照。 |
| `yfth_package_agreement_snapshot` | 用户购买时接受的协议快照，保存协议摘要、hash、客户端版本、IP 和 UA。 |
| `yfth_package_purchase` | 套餐购买绑定，连接用户、门店、订单、规则、协议快照和实例。 |
| `yfth_package_instance` | 支付后生成的套餐实例，是 `member_5980` 身份和权益计划的来源。 |
| `yfth_benefit_template` | 权益模板，定义权益编码、名称、类型、履约类型和单位。 |
| `yfth_monthly_benefit_rule` | 月度权益规则，按规则版本和月份定义权益项数量、开放偏移和过期偏移。 |
| `yfth_benefit_plan` | 套餐实例对应的权益计划，保存月数、起止时间和打开进度。 |
| `yfth_benefit_period` | 每个月的权益周期，保存开放时间、过期时间、状态和履约计数。 |
| `yfth_benefit_item` | 具体权益项，保存数量、可用量、已用量、履约状态和来源月度规则。 |

## 2. 关键唯一约束

- `yfth_package_rule_version.active_key`：同一套餐模板只能有一个发布中的规则版本。
- `yfth_package_product_binding.active_key`：同一套餐/规则/商品/SKU 只能有一个启用绑定。
- `yfth_package_instance.purchase_id`：一次购买只能激活一个套餐实例。
- `yfth_package_instance.order_id`：一个 CRMEB 订单只能激活一个套餐实例。
- `yfth_benefit_plan.package_instance_id`：一个套餐实例只能生成一个权益计划。
- `yfth_benefit_period.plan_id + month_no`：一个计划内月份不可重复。
- `yfth_benefit_item.period_id + source_rule_id`：同一周期不可重复生成同一月度规则权益项。

## 3. 快照字段

套餐权益域所有影响履约和争议处理的字段都需要保存快照：

- 规则快照：价格、月数、权益规则、协议摘要和 hash。
- 商品快照：CRMEB 商品 ID、SKU unique、SKU 价格、商品摘要。
- 门店快照：服务门店、门店主体、能力和收款路由摘要。
- 协议快照：用户接受时的协议标题、摘要、hash、版本、IP 和 UA。

快照用于事后追溯，不作为运行时密钥或支付凭据存储位置。

## 4. 与 CRMEB 原表的关系

- `store_order` 仍负责原订单、支付和退款主流程。
- `store_product` 与 SKU 仍负责商品展示和交易价格配置。
- `system_store` 仍负责门店基础资料。
- `eb_user` 不新增套餐详情字段；`member_5980` 通过 `yfth_user_identity` 派生。

套餐权益表只保存业务实例、计划、周期、权益和快照，不接管 CRMEB 原订单生命周期。

## 5. 后续扩展位

后续服务预约、动态核销、配送履约和权益恢复应新增履约流水表，并引用 `yfth_benefit_item.id`。不要直接在权益项上追加大量一次性字段，也不要把履约明细塞入权益项 JSON。

## 6. 2026-06-24 购买意图与成交快照

本轮新增三类表，解决支付激活读取实时配置和订单重复绑定问题：

- `yfth_package_purchase_intent`：保存不可预测 intent 编号、UID、门店、套餐规则、商品/SKU、协议快照、价格快照、权益 hash、过期时间和已绑定订单。
- `yfth_package_purchase_snapshot`：保存购买成交时的套餐、规则、商品/SKU、协议 hash、主体、支付路由、订单金额和可服务门店快照。
- `yfth_package_purchase_benefit_snapshot`：按月、按权益逐行保存权益名称、类型、履约方式、数量、开放/过期规则、服务能力和适用门店。

购买记录新增字段：

- `intent_id`、`snapshot_id`：串联 intent 与成交快照。
- `order_unique_key`、`order_sn_unique_key`：可空唯一键，只对真实绑定订单生效，避免 MySQL 空值唯一语义误伤未绑定记录。
- `activation_attempt_count`、`last_activation_error`、`activation_retry_at`：用于激活失败后的自动补偿和人工重试。

新增索引：

- `uniq_yfth_pkg_purchase_order_key`
- `uniq_yfth_pkg_purchase_order_sn_key`
- `uniq_yfth_pkg_snapshot_purchase`
- `uniq_yfth_pkg_benefit_snapshot_rule`
- `idx_yfth_benefit_period_open_guard`
- `idx_yfth_benefit_period_expire_guard`

迁移执行前会检测历史重复 `order_id/order_sn` 并抛出冲突记录 ID，不会自动删除非隔离环境数据。
