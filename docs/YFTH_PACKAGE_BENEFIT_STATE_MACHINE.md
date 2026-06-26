# 御方通和 5980 套餐与权益状态机

## 1. 购买状态

| 状态 | 含义 |
| --- | --- |
| `created` | 用户已创建购买绑定，但尚未绑定 CRMEB 订单。 |
| `wait_pay` | 已绑定 CRMEB 订单，等待支付成功后置事件。 |
| `paid` | 预留支付已确认但尚未激活的中间态。 |
| `activated` | 支付成功，已生成套餐实例和权益计划。 |
| `refunding` | CRMEB 售后/退款已申请，等待结果。 |
| `refunded` | 未履约或可全退场景已完成退款。 |
| `closed` | 有履约记录的退款或人工关闭，历史保留。 |
| `refund_failed` | 退款同步失败，需要人工处理或重试。 |

允许的核心转换：

- `created -> wait_pay`
- `wait_pay -> activated`
- `wait_pay -> refunding`
- `paid -> activated`
- `activated -> refunding`
- `refunding -> activated`
- `refunding -> refunded`
- `activated -> closed`
- `refund_failed -> activated`
- `refund_failed -> refunding`

### 1.1 购买 intent 状态

| 状态 | 含义 |
| --- | --- |
| `created` | intent 已创建，尚未开始 CRMEB 建单。 |
| `creating` | 已被一个请求抢占并正在创建 CRMEB 订单；其他请求只能返回处理中。 |
| `bound` | 已绑定唯一 CRMEB 订单、购买记录和成交快照。 |
| `failed` | 建单或绑定失败，已记录错误；仅在无未关闭孤儿订单时允许重新抢占。 |
| `expired` | intent 超时失效，不再允许建单。 |
| `cancelled` | 用户或后台取消，不再允许建单。 |

核心转换：

- `created -> creating`
- `creating -> bound`
- `creating -> failed`
- `failed -> creating`
- `created/failed -> expired`
- `created/failed -> cancelled`

`creating` 必须保存 `creating_request_id`。绑定订单时必须校验当前请求号，避免旧请求把新一轮抢占的 intent 误绑定。已创建 CRMEB 订单但未能绑定的场景，记录为 orphan 并走取消订单补偿，不删除 CRMEB 订单。

## 2. 套餐实例状态

| 状态 | 含义 |
| --- | --- |
| `active` | 套餐有效，派生 `member_5980` 身份。 |
| `refunding` | 退款处理中，履约动作应谨慎拦截。 |
| `refunded` | 未履约或可全退后退款完成，不再派生会员身份。 |
| `closed` | 已履约后关闭或部分退款，保留历史。 |
| `expired` | 到期自然失效。 |

`member_5980` 身份只由 `active` 实例派生。实例退款、关闭或过期后，服务层重新扫描用户所有套餐实例，只关闭不再有 active 来源的身份。

## 3. 权益计划状态

| 状态 | 含义 |
| --- | --- |
| `active` | 计划有效，可按月份开放权益。 |
| `paused` | 人工或异常暂停。 |
| `closed` | 套餐关闭，计划终止。 |
| `refunded` | 退款后计划失效。 |
| `expired` | 计划自然到期。 |

计划与套餐实例一一对应，不能重复生成。

## 4. 月度周期状态

| 状态 | 含义 |
| --- | --- |
| `unopened` | 尚未到开放时间。 |
| `available` | 已开放，可展示/领取/后续履约。 |
| `expired` | 已超过有效期。 |
| `closed` | 实例关闭导致周期关闭。 |
| `refunded` | 退款导致周期关闭。 |

`openDuePeriods` 将到期的 `unopened` 周期转为 `available`；对已经开放的周期，会继续检查延迟开放的权益项和提前到期的权益项。周期过期后，周期与仍可用的权益项进入 `expired`。重复执行应保持幂等。

## 5. 权益项状态

| 状态 | 含义 |
| --- | --- |
| `unopened` | 所属周期未开放。 |
| `available` | 可用。 |
| `used` | 已履约消耗。 |
| `expired` | 已过期且未使用。 |
| `refunded` | 退款关闭。 |
| `closed` | 部分退款或人工关闭后不可继续使用。 |

后续领取、预约、配送和核销应围绕权益项新增履约流水。当前 V1 仅准备数量、可用量、已用量和履约状态字段。

## 6. 退款规则

- 未激活购买：只关闭购买记录，不生成套餐实例。
- 已激活但无履约：套餐实例、计划、周期和权益项进入 `refunded`，`member_5980` 身份重算。
- 已产生履约：套餐实例和计划进入 `closed`，购买标记为部分退款，已用历史保留。
- 退款取消：从 `refunding` 回到可继续使用状态。
- 退款失败：标记失败原因，保留人工重试入口。

所有退款同步只改变御方通和扩展表状态，不改写 CRMEB 退款主流程。

## 7. 2026-06-24 状态机收口

新增和强化的状态语义：

- purchase 新增 `closed_after_partial_refund`，表示已有履约价值，退款后关闭但不能视为未履约全额退款。
- instance 支持 `frozen`、`suspended`，冻结/暂停时 plan 转为 `paused`，未来 period/item 不得被 `openDuePeriods` 打开。
- refunding、refunded、closed、expired 均由 `PackageLifecycleServices` 统一写入，Controller 和后台不直接改实例状态。
- `openDuePeriods` 每条候选周期都会重新锁定并校验 instance=`active`、plan=`active`、refund_status=`none`，避免扫描期间发生退款/关闭后仍误开放。
- 退款失败通过真实原订单恢复到 `activated/active`，仍保持幂等；已关闭或已退款状态不可无审计恢复。

激活幂等状态：

- `processing` 超时或 `failed` 且未超过最大次数时，可由数据库条件更新重新抢占。
- `succeeded` 重放返回原激活结果。
- request hash 不一致直接拒绝。
- 超过最大次数后，自动恢复不再继续抢占，返回 `activation_auto_retry_limit_exceeded` 并等待后台人工处理。

人工激活恢复：

- 人工重试必须有后台操作人和原因，写入 purchase 的 `manual_retry_*` 字段。
- 人工重试使用独立的 `manual_activate` 幂等动作和 `package_activate_manual:{purchase_id}` 键，不复用已经失败到上限的自动激活记录。
- 人工重试前必须确认订单已支付、未取消、未删除、未退款，且购买快照和权益快照完整。
- 并发人工重试只有一个请求能进入 `processing` 并激活；重复请求返回已有实例或处理中结果，不重复生成实例、计划、周期或权益项。

## 8. 2026-06-26 attempt 与 orphan 状态

### 8.1 intent 补充状态

| 状态 | 含义 |
| --- | --- |
| `orphan_paid_pending` | CRMEB 套餐来源订单已支付，但尚无 YFTH purchase。禁止重新建单，等待受控恢复。 |
| `failed` + `orphan_close_status=cancelled` | 未支付 orphan 已通过 CRMEB 原生取消能力关闭，允许重新抢占建单。 |

`creating` 超时不允许直接重置后建单，必须先定位 attempt/order：

- 无订单：记录 `order_creation_timeout_no_order`，intent 转 `failed`。
- 未支付订单：订单取消成功后 intent 转 `failed`，旧订单不得继续支付。
- 已支付订单：intent 转 `orphan_paid_pending`，不得取消或生成第二张订单。
- 已关闭订单：记录关闭状态，允许安全重试。
- 旧请求延迟返回：如果 intent 已是 `bound`，旧 attempt 不得覆盖 `purchase_id`、`bound_order_id` 或 `bound_order_sn`。

### 8.2 order attempt 状态

| 状态 | 含义 |
| --- | --- |
| `creating` | 已持久化 attempt，正在调用 CRMEB 建单。 |
| `order_created` | CRMEB 订单已创建，尚未绑定 YFTH purchase。 |
| `bound` | attempt 对应订单已绑定 purchase。 |
| `orphan_unpaid` | 未支付孤儿订单待关闭或关闭失败待人工处理。 |
| `orphan_paid_pending` | 已支付孤儿订单待恢复。 |
| `orphan_closed` | 未支付孤儿订单已关闭。 |
| `recovered` | 已支付 orphan 已恢复 purchase、快照、实例和权益计划。 |
| `recovery_failed` | 恢复过程失败，保留错误等待人工处理。 |
| `failed` | 建单前后失败且没有可绑定订单。 |

`recovery_status` 用于补充处理结果：`pending`、`closed`、`recovered`、`failed`、`pending_manual`。重复扫描必须幂等：已 `bound/recovered/orphan_closed` 的 attempt 不再重复动作。

### 8.3 扫描动作

- dry-run：只统计 `creating_timeout`、`payable_orphans`、`paid_orphans`、`closed`、`recovered`、`pending_manual`，不改写订单。
- `--close-unpaid`：只关闭未支付且未取消的套餐来源 orphan；已支付订单永不自动关闭。
- `--recover-paid`：只恢复已支付且来源、UID、门店、SKU、价格、协议和快照一致的 orphan；无法确认一致或 intent 已绑定时转人工处理。
