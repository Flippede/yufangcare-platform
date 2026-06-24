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
