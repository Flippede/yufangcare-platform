# 项目交接文档

## Current Fact Snapshot - Headquarters Mall Stage 0 Second Review P1 Closure

- The second Stage 0 read-only architecture review result remains B, conditionally passed; this round closes its remaining P1/P2 documentation gates before another review.
- Current branch: `codex/yfth-hq-mall-stage0-investigation`; start HEAD: `7cc91d7d681efa08a0555d8f809245261e881a4e`; stable `main` / `origin/main`: `de3b2f04e231e7c3115f33b1ef1450ccf2fbb084`.
- This round only revises the compatibility investigation, data-model proposal and this handoff. No PHP, migration, route, API, frontend, test, database or existing entry is changed.
- Ordinary future first attribution is permitted only for pristine initial unassigned: `status=unassigned`, `store_id=0`, `authority_version=0`, `status_reason_code=initial_placeholder`, no attribution event and no prior active/paused ownership. Any inconsistent version/reason/event state fails closed.
- Historical no-successor unassigned uses `store_id=0`, `authority_version>0`, `status_reason_code=store_terminated_no_successor` and a matching attribution event. It cannot be rebound by referral, membership, package or store business paths; only a future headquarters-controlled takeover/recovery may act on it.
- No-successor closure uses historical unassigned for attribution and paused for the existing active referral. Attribution paused is reserved for risk, qualification suspension or temporary closure while the original `store_id>0` ownership remains.
- Structured attribution reason codes are frozen, including `initial_placeholder`, `store_terminated_no_successor`, `temporary_risk_pause`, `temporary_qualification_pause`, `headquarters_correction_closed` and `account_closed`.
- Attribution placeholder starts at authority version 0 without an event. First real attribution moves to version 1 and writes version-1 `attribution_created` in the same transaction; every later change increments exactly once and has the matching event version.
- A new referral relation starts at `relation_version=1` and must atomically write version-1 `relation_created`. Resume/pause/close/invalid transitions increment the version and write `relation_resumed`, `relation_paused`, `relation_closed` or `relation_invalid`; membership activation is `relation_closed` with `close_reason=membership_activated`.
- Attribution/referral `source_unique_key` is a nullable, lowercase 64-character canonical SHA-256 digest with fixed domain/event/source ordering. It is unique only within its own table; absent sources use NULL, never an empty string, and clients cannot supply the digest.
- Each request calls `IdempotencyRecordServices::begin()` once. Up to three deadlock/lock-wait transaction attempts reuse the same processing ownership; they do not begin, reacquire or fail early. Success completes once and final failure fails once.
- Stage 1A creates only four business tables plus Model/DAO/domain services, qualification policy, idempotency, authority events, general audit, migration and tests. It creates no `system_menus`, API permission, Controller, route, Command, Listener, Job, schedule, page, config switch, production test provider or production recommendation entry.
- Headquarters ordinary read and authority-event audit read are separate Stage 1B permission/DTO semantics. Ordinary readers cannot see event/version/operator/source-id/internal reason fields; explicitly authorized auditors receive a limited structured event DTO but still never receive source digests, idempotency secrets, raw audit JSON or private identity/payment data.
- Current tables no longer include a redundant latest-event pointer. Current and event correspondence is exclusively `current_id + authority_version/relation_version`; latest events are queried by the versioned timeline.
- Stage 1A migration writes no permissions and only creates four tables. Fixed rollback order is referral event, attribution event, referral current, attribution current. Duplicate/half-state recovery may add only missing compatible tables/indexes after signature and data checks; unsafe mismatch fails without rewriting data.
- Stage 1A test fixtures exist only in isolated MySQL test databases and are cleaned by database disposal, transaction rollback or explicit test cleanup. No migration seeds business fixtures, no production data is read, and no production import/conflict tool is built.
- Future permanent membership identity projection name is frozen as `member_yfth`; the permanent membership instance remains authoritative, `member_yfth` may coexist with `member_5980`, and Stage 1A/1B implements neither membership nor this projection.
- Stage 0 documents remain blocked from main pending another read-only architecture review. Stage 1A remains unauthorized; after review passes, the documents may be merged, and only then may project control separately decide whether to authorize Stage 1A.
- No production deployment, production database connection, production migration, server modification or WeChat upload was performed.

## Historical First Review P1 Closure Snapshot - Superseded By Second Review Closure Above

- Stage 0 first independent architecture review result: B, conditionally passed; the documentation required P1/P2 closure before another read-only review.
- Current branch: `codex/yfth-hq-mall-stage0-investigation`; start HEAD for this documentation closure: `3dad02964d1f14fb056271606102ccbd21020ab7`; stable `main` / `origin/main`: `de3b2f04e231e7c3115f33b1ef1450ccf2fbb084`.
- This round only revises `docs/YFTH_HQ_MALL_STAGE0_COMPATIBILITY_INVESTIGATION.md`, `docs/YFTH_HQ_MALL_STAGE0_DATA_MODEL_PROPOSAL.md`, and this handoff. No business code, migration, route, API, frontend, test, database, or old entry is changed.
- Permanent attribution is frozen as one `yfth_hq_customer_attribution_current` row per numeric CRMEB UID plus an independent append-only `yfth_hq_customer_attribution_event`. `UNIQUE(uid)` is the current-authority and locking boundary; no separate attribution guard table or string attribution active key is used.
- Attribution current states are frozen: `active` keeps `store_id>0`; `paused` keeps `store_id>0` and occupies the UID authority; `unassigned` uses `store_id=0`, but only the pristine version-0 initial placeholder permits a later ordinary trusted first attribution; `closed` and historical unassigned cannot be rebound by ordinary paths.
- First UID handling uses insert-first/atomic-upsert semantics to retain an `unassigned` version-0 current row; unique conflict requires reread. Business transactions then lock this row and never depend on locking a missing row.
- Active referral is frozen as `yfth_hq_active_referral_current` plus independent `yfth_hq_active_referral_event`. `active` and `paused` retain numeric `active_referred_uid=referred_uid`; `closed` and `invalid` clear it. Paused relations cannot be stolen, and membership-activated closed relations cannot be restored.
- Attribution and referral authority events are separate tables, append-only, version-unique, and mandatory in the same transaction as current-state changes. Event failure rolls back current state. `AuditEventServices::recordSafely()` remains general operation audit and cannot replace either authority event timeline.
- Stage 1 is split. Stage 1A is Authority Foundation only: four new authority tables, internal services, fail-closed qualification policy, consistency checks, idempotency, events, audit, migration and tests. It exposes no real referral-creation API, fixture API, bypass switch, membership, dynamic code, sale, reward, refund, takeover, CRM projection, entry hiding, or production deployment.
- Stage 1B is a separately authorized Read-only Surface after Stage 1A passes its own review: user-own current attribution, store-scoped minimum attribution, and headquarters attribution/referral/event read-only APIs and views. It contains no attribution/referral/state mutation.
- The production referral qualification policy must query the future permanent-membership authority and fail closed while that authority is unavailable. It must not use `member_5980`, identity projection, old candidate, CRM relation, fixture endpoints, commands, environment switches, or admin bypasses.
- All referrer/referred operations collect every involved UID, de-duplicate and sort numerically, ensure current rows exist, then lock attribution current rows in ascending UID order before referral and permanent-membership qualification locks. Only deadlock/lock-wait errors may retry, at most three complete transactions with short random backoff.
- Stage 1A/1B do not write `yfth_customer_relation`. Stage 1B may combine-read a safe CRM summary; any later projection is independently reviewed, compensable and idempotent, and projection failure cannot roll back permanent attribution.
- New authority tables start empty with zero automatic migration. Conflicts in customer relation, old candidate, `member_5980`, or old ledger do not block empty-schema creation; they block import, real write-entry enablement and production rollout. Same-name schema signature mismatch, invalid new-table data, unrecoverable half-migration, or migration-record conflict does block schema migration. Stage 1A has no permission seed.
- Repository fact corrected: `member_5980` is derived only from currently effective `status=active` package instances. Refunding/refunded/closed/expired transitions trigger recomputation, and the identity remains only while another active instance exists.
- Migration fact corrected: recent high-risk migrations demonstrate guarded/upsert/down/half-recovery patterns, while early foundation/package/customer migrations include one-time `change()` implementations. Stage 1A must implement and verify the current highest standard rather than assume all YFTH migrations are rerunnable.
- Later technical directions are frozen but excluded from Stage 1A/1B: a separate `yfth_business_dynamic_code` with server-owned scenes; permanent membership authority projected as price/term-neutral `member_yfth`; compensable CRM projection; and a new cyclic reward domain with rule/version, sequence account, observing candidate, finalized ledger and append-only reversal.
- Stage 1A remains **unauthorized**. Next action is another Stage 0 read-only architecture review. Only after that review passes and the documentation is merged may project control separately decide whether to authorize Stage 1A; Stage 1B requires a later separate decision.
- No production deployment, production database connection, production migration, server modification, or WeChat upload was performed.

## Historical Stage 0 Initial Investigation Snapshot - Superseded By P1 Closure Above

- Current Stage 0 branch: `codex/yfth-hq-mall-stage0-investigation`; stable `main` / `origin/main` baseline: `de3b2f04e231e7c3115f33b1ef1450ccf2fbb084`.
- This round is a read-only compatibility and data-model investigation. No PHP, Vue, JavaScript, migration, route, test, database, existing entry, or business state machine was changed.
- Stage 0 outputs: [YFTH_HQ_MALL_STAGE0_COMPATIBILITY_INVESTIGATION.md](YFTH_HQ_MALL_STAGE0_COMPATIBILITY_INVESTIGATION.md) and [YFTH_HQ_MALL_STAGE0_DATA_MODEL_PROPOSAL.md](YFTH_HQ_MALL_STAGE0_DATA_MODEL_PROPOSAL.md).
- Investigated compatibility scope: legacy online 5980 package purchase/payment/refund/recovery and ten-month benefits; `member_5980`; old referral candidate/attribution/reward ledger; franchise customer CRM; service appointment dynamic codes; store workbench; user/admin routes, pages, menus, migrations, and regression checks.
- Repository fact: `member_5980` is derived only from currently effective active online package instances; old referral candidates expire after 90 days; `yfth_customer_relation` is an operational store CRM relation; `yfth_service_dynamic_code` is appointment-writeoff specific. None is suitable for reinterpretation as the new permanent authority.
- Initial proposal status: this snapshot records the pre-review investigation. The P1 closure above supersedes its open design language and freezes one numeric-UID current attribution row, separate attribution/referral events, and the four-table Stage 1A boundary.
- Stable code that must remain: CRMEB mall/order/payment/refund/logistics/page-decoration core; legacy 5980 package services, listeners, routes, recovery commands, UI, migrations, tests, benefit lifecycle and monthly fulfillment; appointment/benefit lock/writeoff; old referral ledger compatibility; customer CRM; audit and idempotency foundations.
- Coexistence recommendation: no automatic conversion of old 5980 instances, `member_5980`, old referral candidates/ledgers, customer relations, or appointment codes. Existing history, refund, recovery, benefit, appointment, writeoff, fulfillment, scan, and read-only paths remain operational. Future hiding of old new-purchase/referral-entry UI requires a separate reviewed task.
- Initial-data recommendation: zero automatic migration into the new authorities. The repository cannot prove production data is empty; existing customer relations may only become manually verified candidates after a production-preflight read-only report.
- Stage scope is now split: Stage 1A is the internal Authority Foundation and Stage 1B is a separately authorized Read-only Surface after Stage 1A review. Neither stage opens real referral creation while permanent membership authority is absent.
- Stage 1A/1B exclude identity/membership/package codes, offline sales, permanent membership activation, reward amounts/sequences, headquarters-mall revenue, settlement, refund, takeover, CRM writes, legacy-entry hiding, and production deployment.
- P1 closure result: current authority, state semantics, event-table separation, fail-closed qualification, API privacy, numeric-UID lock order, migration indexes and zero-import boundary are frozen in `YFTH_HQ_MALL_STAGE0_DATA_MODEL_PROPOSAL.md`; they are no longer parallel options.
- Business parameters remain owned by project control and are not selected here: B/C revenue ratios, observation days, refund/downline handling, partial-refund adjustment, membership benefits, unassigned recovery, administrative-region source, privacy authorization, and settlement evidence.
- Stage 1A is **not authorized** by this document. The next action is another independent Stage 0 read-only architecture review; implementation requires that review to pass, documentation closure, and a new explicit project-control authorization for Stage 1A.
- No production deployment, production database connection, production migration, server modification, or WeChat upload was performed.

## Current Fact Snapshot - Final Headquarters Mall Scope Documentation Closure

- 第三次只读架构复核结论为 A：仅批准本轮文档合并；当前没有 Blocker、P1 或 P3，剩余问题均为后续具体阶段的 P2 参数门禁。
- 文档分支 `codex/yfth-hq-mall-membership-referral-scope-doc` 已通过 `git merge --ff-only` 合并进入 `main`；合并前 main 为 `30aa69de6037123eb8b593ceb66c03740912549e`，已审核文档提交为 `c60a7f9a03fadff3795cb86165d832cd208c2a74`。
- 文档分支本地和远端均继续保留，不删除、不改写历史。
- 当前最新产品依据为 [YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md](YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md)；当前事实入口为本文顶部最新 `Current Fact Snapshot`。
- `PRODUCT_REQUIREMENTS.md`、原始 DOCX、`REQUIREMENTS_GAP_ANALYSIS.md` 历史正文和旧发布范围仅作为历史或兼容依据，不得覆盖当前范围或作为当前开发顺序。
- 现有线上 `5980` 套餐、十个月权益、月度履约、产品额度、旧推荐台账、退款补偿和恢复能力继续保留；不得全局清理或重命名旧 `5980` 技术标识。
- 当前未启动阶段零，也未启动阶段一至阶段七。文档合并不等于允许业务开发。
- 下一步必须由项目主控单独授权阶段零；阶段零只能进行只读兼容与数据模型调查，完成后必须再次执行只读架构审核。只有阶段零审核通过且项目主控再次授权后，才允许进入阶段一。
- 剩余 P2：B 商家总部商城收益比例和 C 端普通商品消费子分配比例待确认，均阻塞阶段五；三三制观察期默认天数待确认，阻塞阶段四真实解冻和上线；会员退款后已有下级 active 关系处置待确认，阻塞阶段二完整退款和阶段四真实解冻；部分退款比例冲正规则、会员福利具体配置待确认；无接管状态恢复条件和行政区 code 来源待确认，阻塞阶段七；隐私授权和结算凭证标准待确认，阻塞对应敏感页面和阶段六上线。开发人员不得自行选择上述业务参数。
- 本轮未部署生产、未连接生产数据库、未执行生产迁移、未上传微信平台。
- 最终 `main` 和 `origin/main` 提交应以本次文档闭环提交及推送后的真实 Git HEAD 为准。

## Current Fact Snapshot - Headquarters Mall Membership And Referral Scope Third Review Closure

- Current `main` baseline for this documentation round: `30aa69de6037123eb8b593ceb66c03740912549e`.
- 第一次只读架构预审结论为 B；上一轮关闭旧文档引用、可信线下成交、三三制责任主体和 active 推荐有效期四个 P1。第二次只读架构审核结论仍为 B，本轮继续关闭 `PRODUCT_REQUIREMENTS` 权威性污染、目标 UID 可信绑定以及永久归属未纳入成交原子事务三个范围门禁。
- 第三次复核前发现 `REQUIREMENTS_GAP_ANALYSIS.md` 仍保留旧“产品依据”和历史开发顺序的当前式表述，本轮已完成最终来源统一：当前唯一产品依据为 `docs/YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md`；`PRODUCT_REQUIREMENTS.md`、原始 DOCX 和差距分析历史正文只用于追溯早期产品与兼容实现，不得作为当前开发顺序。
- 本轮只修订 Markdown 需求文档，不写业务代码、接口、迁移、测试或前端；旧 `9800 Direct Referral Release Scope - Frozen Draft` 已被新方案取代。
- C 端始终只显示总部统一商城，总部商城收款进入总部支付主体；B 商家没有独立商城。
- 首次注册用户默认不绑定商家，可正常浏览和购买总部商城商品，绑定前历史订单不追溯收益。
- 用户可通过有效一级直推、目标用户绑定的会员确认或线下套餐成交确认形成永久 B 商家归属；会员与套餐确认事务均须锁定归属保护记录，并以数据库唯一约束阻止跨店抢占。
- 会员和业务套餐是两个独立的 `9800` 线下产品：会员赋予一级直推资格和总部福利，第一版永久有效且不续费；套餐使用“线下套餐成交确认”术语，不代表预约核销或服务权益消费。
- 动态码分为 `customer_identity`、`membership_confirmation` 和 `package_sale_confirmation` 三个场景；均须哈希存储、短时单次、先锁行再校验，并在业务写入事务内标记已使用和清除 active key。
- pending 会员成交必须先通过 C 端客户身份码绑定真实 `target_uid`，再生成仅限该目标用户确认的会员码；禁止任意 UID/手机号输入、通用公开码、抢领或事后改绑。
- 会员确认事务必须原子完成永久归属、奖励候选、关闭 active 推荐关系、创建永久会员、授予直推资格和消费会员码；线下套餐成交确认事务必须原子完成成交、首次永久归属、奖励候选和消费套餐码，且不得改变会员或关闭 active 推荐关系。
- 现有 `5980` 技术标识、表、类、事件、身份、路由、测试和状态机默认保留兼容；旧入口可以受控隐藏，但清理、重命名、数据重建或移除必须另立专项并独立审核。
- 会员与套餐有效成交共用循环 15% / 25% / 60% 序列；同一非会员下级在 active 层级内重复购买套餐可重复产生奖励候选。
- 三三制奖励由发生线下会员/套餐成交的 B 商家从该笔成交收入中承担，总部不是默认付款人。
- active 推荐关系长期有效，直到被推荐人成为会员或由总部受控关闭；不采用旧候选 90 天自动到期。
- 下级购买会员产生一次奖励候选后立即脱离原 active 直推层级，后续成交不再为原直推人产生奖励或消费分成。
- 会员全额退款不恢复原 C1 active 推荐关系；其已有下级 active 关系的处置仍未冻结，不阻塞文档合并，但阻塞会员全额退款上线和循环奖励真实解冻。无接管门店期间，总部商城新订单不产生 B/C 收益。
- 总部商城订单的 B 商家总收益包含 C 端直推子分配，总部只向 B 商家结算一笔，不重复向 C 端支出。
- B 商家终止时按区县、市、省顺序生成候选，由总部人工二次确认接管；历史订单和收益不追溯、不重算。
- 当前仍未允许业务代码开发；下一步是第三次只读架构复核，复核通过后才允许合并文档分支。文档合并不自动启动开发；合并后必须由项目主控明确授权阶段零只读兼容与数据模型调查，阶段零再经只读审核后才可单独授权阶段一。

## Historical Superseded Snapshot - 9800 Direct Referral Release Scope - Frozen Draft

- **Superseded：本历史快照已被 Headquarters Mall Membership And Referral Scope 取代，不得继续作为当前需求或代码开发依据。最新产品依据为 [YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md](YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md)。**
- 历史说明：当时范围口径被标记为 Frozen Draft，但现已废弃，以下内容仅保留用于追踪当时决策。
- 本轮为 Architecture Auditor B 审核后的文档修订，不写业务代码、不改接口、数据库、前端页面或既有业务模块。
- 新上线口径：C 端家庭康养套餐 `9800`，B 端标准加盟店 `98000`；历史 `5980` / `59800` 口径需在后续专项中受控迁移。
- P2 已补充：永久绑定与闭店重购生命周期、33 制序号边界与分配时点、B 商家结算角色与权限矩阵、城市合伙人三级收益归属基数。
- 只有开通 `9800` 店铺会员的 C 端用户拥有一级直推资格；开通后永久绑定 B 商家，并脱离此前直推人的 active 层级；闭店时仅总部受控动作可关闭 active 绑定并保留历史。
- C 端只做一级直推；奖励仅记录应返台账、状态、凭证和线下结算，不做自动打款、钱包或 CRMEB 分销佣金。
- B 商家负责发货、自提、门店库存和线下返现处理；B 商家倒闭后会员降级为普通用户，并由绑定城市合伙人按最小必要信息原则线下跟进。
- 城市合伙人是招商合伙人，分省/市/县三级；B 商家仅直接绑定一个最低实际归属合伙人，上级关系由树推导，收益比例和收益层级待规则版本确认。
- P3 已统一命名为 `9800 Direct Referral Release Scope - Frozen Draft`。
- 历史门禁：该旧方案当时要求确认价格迁移、支付主体、比例、观察期、库存和区域治理；这些要求不得再解释为当前开发入口。

## Current Fact Snapshot - Final Monthly Benefit Claim And Fulfillment V1 Closure

- Current branch after merge: `main`.
- Preserved feature branch: `codex/yfth-monthly-benefit-fulfillment-v1` (local and remote).
- Main before merge: `fa3edef7d9e48427f235cd458dbdead384b83341`.
- Final reviewed feature commit: `4627ef8a5fafac3de2216ed6168af640a954f7b3`.
- Merge method: `git merge --ff-only codex/yfth-monthly-benefit-fulfillment-v1`; no merge commit, squash, rebase, or history rewrite was used.
- Architecture review conclusion: A, passed; no Blocker/P1/P2/P3 remains.
- Completed capabilities: product-type monthly benefit claim; headquarters confirm, reject, prepare, ship, complete, cancel, and exception workflow; same-store pickup confirmation; fulfillment timeline; unified audit; idempotency; headquarters admin page; user monthly-benefit pages; and store-workbench pickup page.
- New tables: `yfth_benefit_fulfillment` and `yfth_benefit_fulfillment_event`.
- User APIs: `/api/yfth/monthly_benefit/current`, `/api/yfth/monthly_benefit/history`, `/api/yfth/monthly_benefit/fulfillment/:id`, `/api/yfth/monthly_benefit/claim`, and `/api/yfth/monthly_benefit/fulfillment/:id/cancel`.
- Store-workbench APIs: `/api/yfth/store_workbench/monthly_benefit/pickup`, pickup detail, and pickup confirm.
- Headquarters APIs: `/adminapi/yfth/monthly_benefit/fulfillment`, detail, confirm, reject, prepare, ship, complete, exception, and cancel.
- Express state-machine closure: `pending_confirm -> confirmed -> preparing -> shipped -> completed`; headquarters complete accepts only `shipped` for express fulfillment.
- Self-pickup state-machine closure: `pending_confirm -> confirmed -> preparing -> pickup_confirm -> completed`; store `pickup_confirm` accepts only `preparing`.
- Forbidden transitions remain closed: `confirmed -> pickup_confirm -> completed` and headquarters `confirmed/preparing -> completed`. Illegal transitions do not consume the benefit, mutate counters, write a final event, or write a final-consumption audit.
- Strict-migration closure: all monthly-fulfillment `menu_name` values are at most 32 characters; table creation is `hasTable` guarded; menu seeding is idempotent by `unique_auth`; `down()` removes all monthly permissions and both monthly tables.
- MySQL 8.0.46 default strict-mode migration `run -> rollback -t 0 -> rerun -> duplicate run` passed. Half-state recovery from two existing tables, partial permissions, and no migration record also passed.
- Concurrency closure: two independent PHP processes with distinct idempotency keys safely confirmed one `preparing` pickup. Only one final benefit consumption, one quantity/counter update set, one pickup event, one fulfillment audit, and one package-benefit consumption audit occurred.
- Audit request-id closure: monthly idempotency records retain their normalized long keys; audit request ids exceeding shared `VARCHAR(64)` are deterministically SHA-256 normalized for both fulfillment and package-benefit consumption audits.
- CRMEB boundary: no `store_order` creation; no payment, refund, logistics-order, product/SKU stock, sales, or quota mutation; no balance, points, brokerage, distribution, commission, withdrawal, settlement, or revenue-sharing write.
- Adjacent YFTH boundary: no supply-chain inventory balance/ledger write, product-quota account/ledger write, or referral-reward ledger/settlement write.
- Validation evidence: Architecture Auditor A review; PHP syntax; monthly contract check with 119 assertions; monthly source guard; MySQL 8.0.46 strict migration lifecycle and half-state recovery; isolated service-level real-flow; true two-process pickup concurrency; package-benefit, service-appointment, supply-chain, and product-quota contract checks; admin production build; uni-app multi-role shell and request fallback checks; H5 production build; mp-weixin production compile; and `git diff --check` all passed.
- Merge-preparation rerun: monthly contract (119 assertions), monthly source guard, package-benefit, service-appointment, supply-chain, product-quota, uni-app multi-role shell, request fallback, and `git diff --check` passed. The already reviewed isolated MySQL and frontend production builds were not repeated during this merge-only closure.
- Production status: no production deployment, production database connection, production migration, server modification, WeChat upload, production AppID, private key, or upload key was used.
- Not implemented: CRMEB logistics-order integration, automatic shipping, real courier API, product-quota offset, supply-chain stock deduction, delivery after-sale reversal, completed-benefit recovery, notifications, production deployment, and production database migration.
- Final main and origin/main commit should be read from real Git HEAD after this documentation closure commit and push.

## Current Fact Snapshot - Monthly Benefit Claim And Fulfillment V1 Strict Migration Closure

- Current development branch: `codex/yfth-monthly-benefit-fulfillment-v1`; start HEAD for this closure: `753106099f88bc92f6cedd4c37b23642c2e85246`; stable `main` and `origin/main`: `fa3edef7d9e48427f235cd458dbdead384b83341`.
- Scope remains product-type monthly benefit claim, headquarters fulfillment, same-store pickup confirmation, events, unified audit, idempotency, admin UI, user UI, and store workbench pickup. No new business module was added in this closure.
- New strict-mode P1 root cause: English menu labels seeded by `20260712100000_create_yfth_monthly_benefit_fulfillment_tables.php` exceeded CRMEB `system_menus.menu_name VARCHAR(32)`. MySQL 8.0.46 default `STRICT_TRANS_TABLES` rejected the seed and could leave both fulfillment tables plus only part of the menu tree without a migration record.
- Strict migration P1 closure: all ten literal/root/page/API menu names inspected by the contract are at most 32 characters; the longest current seed name is 8 characters. `unique_auth`, `api_url`, `methods`, `auth_type`, and menu hierarchy were not changed.
- Half-migration closure: table creation is guarded by `hasTable`; menu seeding is an idempotent `unique_auth` upsert; `down()` removes all nine monthly fulfillment permissions and both monthly fulfillment tables.
- MySQL evidence: official MySQL Community 8.0.46 on temporary database `yfth_monthly_benefit_strict_validation` retained its default strict SQL mode for every YFTH migration command. `migrate:run -> migrate:rollback -t 0 -> migrate:run -> duplicate migrate:run` passed. Run/rerun produced two tables and nine distinct permissions; rollback removed both tables and all nine permissions; duplicate run created no duplicates.
- Half-state recovery evidence: with both tables present, only two monthly permissions present, and migration record `20260712100000` removed, `migrate:run` recovered to two tables, nine distinct permissions, and one migration record. A following duplicate run remained idempotent.
- State-machine P1 closures remain intact: express delivery requires `pending_confirm -> confirmed -> preparing -> shipped -> completed`; self pickup requires `pending_confirm -> confirmed -> preparing -> pickup_confirm -> completed`; illegal transitions do not consume benefits or write final events/audits.
- P2 concurrency closure: the isolated real-flow starts two independent PHP processes with distinct idempotency keys against one `preparing` self-pickup fulfillment. Both return a safe completed result while the benefit item is consumed once, quantity reaches zero once, period/package counters increment once, and only one final pickup event plus one fulfillment and one package-benefit consumption audit are written.
- Audit boundary closure found during strict real-flow: monthly idempotency keys are 80 characters while `yfth_audit_event.request_id` is `VARCHAR(64)`. Long audit request ids are now deterministically SHA-256 normalized before both fulfillment and package-benefit audit writes; idempotency storage itself remains unchanged.
- Validation executed: PHP syntax for every PHP file changed from `main`; monthly contract check with 119 assertions; source guard; isolated MySQL 8.0.46 service-level real-flow including true two-process pickup; package-benefit, service-appointment, supply-chain, and product-quota contract checks; admin production build; uni-app multi-role shell and request fallback checks; H5 production build; mp-weixin production compile; `git diff --check`.
- Frozen boundaries: no CRMEB order/payment/refund/product/SKU stock mutation; no supply-chain inventory or product-quota write; no reward, balance, points, brokerage, commission, settlement, or revenue-sharing write.
- Not implemented: CRMEB logistics order integration, automatic shipping, real courier API, product-quota offset, supply-chain stock deduction, delivery after-sale reversal, completed-benefit recovery, notifications, production deployment, and production database migration.
- Production status: no production deployment, production database connection, production migration, server modification, WeChat upload, production AppID, private key, or upload key was used.
- Next gate: repeat the read-only architecture review on this strict-mode and concurrent-pickup closure before any merge decision. Final feature commit should be read from real Git after this closure commit.

## Current Fact Snapshot - Final Product Quota / Return Goods Quota Ledger V1 Closure

- Current branch after merge: `main`.
- Preserved feature branch: `codex/yfth-product-quota-ledger-v1`.
- Main before merge: `3ebd2135ef9d8146ad655c5965f63d134db9c6b5`.
- Final reviewed feature commit: `86104b28cf36b1f90f5340daef6e2ab3a18b256f`.
- Merge method: `git merge --ff-only codex/yfth-product-quota-ledger-v1`.
- Architecture review conclusion: A, passed; no Blocker/P1/P2/P3 remains.
- Completed capabilities: independent product-equivalent quota account, immutable quota ledger, headquarters manual grant draft, grant confirm, grant reject, grant reverse, manual increase, manual decrease, freeze, unfreeze, close, source snapshots, headquarters admin page/API, and franchisee/store-manager read-only miniapp display.
- New tables: `yfth_product_quota_account`, `yfth_product_quota_ledger`, `yfth_product_quota_grant_order`, `yfth_product_quota_adjustment`, and `yfth_product_quota_source_snapshot`.
- P1 closure: grant creation requires a non-empty operation key; the grant operation key is normalized server-side; same-key/same-payload grant replay returns the existing result; same-key/different-payload grant replay is rejected.
- P1 closure: manual adjustment requires a non-empty operation key; the manual adjustment key is normalized server-side; manual adjustment rechecks dedupe after locking the quota account row; duplicate manual adjustment does not mutate balance twice.
- P1 closure: `idempotency_key` and `dedupe_key` are non-null mandatory fields with unique guards; the admin page generates operation keys and guards duplicate local submits.
- Validation: Architecture Auditor A review passed; PHP syntax passed; product quota contract check passed with 104 assertions; product quota default source guard passed; isolated MySQL 8.0.46 product quota real-flow passed; MySQL 8.0.46 migration `run / rollback -t 0 / rerun / duplicate run` passed.
- Validation: supply-chain, referral-reward, and franchise-opening contract checks passed; admin production build passed with existing warnings only; uni-app request/context Node checks passed; H5 production build passed; `mp-weixin` production compile passed; `git diff --check` passed.
- CRMEB and financial boundary: no CRMEB `store_order` creation, no CRMEB order/payment/refund mutation, no CRMEB product/SKU stock mutation, no sales/quota mutation, and no user balance, points, brokerage, distribution, commission, withdrawal, settlement, or revenue-sharing writes.
- Supply-chain / reward / opening boundary: no purchase quota offset, reservation, consumption, purchase release/recovery, purchase after-sale quota return, referral reward auto-conversion, or opening auto-grant.
- Production status: no production deployment, no production database connection, no production migration, no server modification, no WeChat upload, and no production AppID, private key, or upload key was used.
- Not implemented: purchase order quota offset, reservation, consumption, release, recovery, reward ledger conversion into product quota, franchise opening automatic quota grant, purchase after-sale return quota, online payment, withdrawal, settlement, revenue sharing, CRMEB distribution integration, production deployment, and production database migration.
- Final main and origin/main commit should be read from real Git HEAD after this documentation closure commit and push.

## Current Fact Snapshot - Product Quota / Return Goods Quota Ledger V1 Independent Validation Evidence

- Current development branch: `codex/yfth-product-quota-ledger-v1`.
- Validation evidence commit basis before this documentation-only closure: `64441c0043c46766b04341a485d003ffaf6b1c52`.
- Main baseline remains `3ebd2135ef9d8146ad655c5965f63d134db9c6b5`; this round does not merge or push `main`.
- This round only supplements independent validation evidence for Product Quota / Return Goods Quota Ledger V1. No business code, migration structure, API contract, admin page behavior, uni-app page behavior, payment, inventory, order, reward, settlement, or quota business scope was changed.
- PHP runtime used: `C:\Users\zhangxu\.codex\tools\yfth-runtime\php-7.4.33\php.exe`, PHP 7.4.33. No loaded `php.ini`; `pdo_mysql` and `mysqli` were enabled through CLI `-d extension_dir=C:\Users\zhangxu\.codex\tools\yfth-runtime\php-7.4.33\ext -d extension=pdo_mysql -d extension=mysqli`. `php -m` confirmed `PDO`, `pdo_mysql`, and `mysqlnd`.
- MySQL runtime used: `C:\Users\zhangxu\.codex\tools\yfth-runtime\mysql-8.0.46-winx64\bin\mysqld.exe`, MySQL Community Server 8.0.46, temporary local port `33253`, temporary database `yfth_product_quota_validation_20260709192058`.
- The CRMEB baseline schema was imported into the temporary database with `sql_mode=NO_ENGINE_SUBSTITUTION`. A temporary `.env` was written only for the isolated run, restored afterward, and verified by SHA256 hash; no temporary `.env`, data directory, database password, log, or MySQL data file entered Git.
- MySQL 8.0.46 migration evidence: `php think migrate:run` created all five product quota tables; `idempotency_key` and `dedupe_key` were `NOT NULL` with empty-string defaults; all four unique guards existed; 12 `yfth-product-quota*` permissions existed.
- Rollback evidence: `php think migrate:rollback -t 0` removed the five product quota tables and the `yfth-product-quota*` permissions. Rerun restored the five tables, 12 permissions, and unique guards. Duplicate `php think migrate:run` completed without duplicating product quota permissions.
- Isolated MySQL real-flow evidence: `YFTH_PRODUCT_QUOTA_REAL_FLOW_EXECUTE=1` and `YFTH_REAL_FLOW_ISOLATED_DB=1 php crmeb/tests/yfth_product_quota_real_flow_check.php` passed on MySQL 8.0.46.
- Real-flow coverage confirmed: missing grant key rejected; duplicate grant create returns the existing grant; same-key different-payload grant rejected; duplicate grant confirm keeps a single ledger and does not increase balance twice; missing adjustment key rejected; duplicate manual increase/decrease returns existing adjustment and mutates balance only once; same-key different-payload adjustment rejected; frozen account blocks amount adjustment; product quota audit events are written; CRMEB order/product/SKU/user boundary snapshots remain unchanged.
- Regression evidence: PHP syntax check passed for all PHP files changed in `main..HEAD`; `yfth_product_quota_contract_check.php` passed with 104 assertions; default source-guard `yfth_product_quota_real_flow_check.php` passed; supply-chain, referral-reward, and franchise-opening contract checks passed.
- Frontend evidence: `template/admin` `npm run build` passed with existing CSS order, asset-size, entrypoint-size, and Browserslist warnings only. Uni-app request/context Node checks passed.
- Mobile production evidence: H5 production build using HBuilderX `uniapp-cli` and Node 18.20.8 passed; output directory `template/uni-app/unpackage/dist/build/h5` contained 427 files / 13,564,863 bytes, with existing asset-size warnings only.
- Mobile production evidence: `mp-weixin` production compile using HBuilderX `uniapp-cli --no-opt` and Node 18.20.8 passed; output directory `template/uni-app/unpackage/dist/build/mp-weixin` contained 1,209 files / 7,731,104 bytes, with existing skeleton `:key` hints and component subpackage suggestions only.
- No WeChat upload was performed. No production AppID, private key, or WeChat upload key was used.
- Production status: no production deployment, no production database connection, no production migration, no server modification, no `main` merge, and no `main` push were performed.
- Still not implemented: purchase quota offset, reservation, consumption, purchase after-sale quota return, referral reward auto-conversion, opening auto-grant, real payment, withdrawal, online settlement, revenue sharing, and production rollout.
- Next gate: read-only architecture re-review can use this independent evidence to decide whether Product Quota / Return Goods Quota Ledger V1 is ready for controlled merge preparation.

## Current Fact Snapshot - Product Quota / Return Goods Quota Ledger V1 P1 Idempotency Closure

- Current development branch: `codex/yfth-product-quota-ledger-v1`.
- Main baseline remains `3ebd2135ef9d8146ad655c5965f63d134db9c6b5`; this feature branch is not merged to `main` in this P1 closure round.
- P1 root cause: headquarters grant creation and manual quota adjustment accepted optional or empty operation keys, while `yfth_product_quota_grant_order.idempotency_key` and `yfth_product_quota_adjustment.dedupe_key` were nullable unique columns, allowing multiple `NULL` writes and repeated balance changes through double click, retry, or replay.
- P1 closure: grant creation now requires a client operation key, normalizes it server-side into `product_quota_grant_create:{admin_id}:{hash}`, returns the existing grant for same-key/same-payload replay, and rejects same-key/different-payload replay.
- P1 closure: manual adjustment now requires a client operation key, normalizes it server-side into `product_quota_adjustment_post:{admin_id}:{hash}`, rechecks dedupe after locking the quota account row, returns the existing adjustment result for duplicate replay, and rejects payload mismatch.
- P1 closure: `idempotency_key` and `dedupe_key` are non-null mandatory strings in the migration and remain protected by unique indexes.
- P1 closure: admin `productQuota` page generates operation keys for grant and adjustment dialogs and guards duplicate local submits with `grantSubmitting` and `adjustSubmitting`.
- P1 closure: service-level real-flow coverage was extended for missing keys, duplicate grant create, duplicate grant confirm, duplicate manual increase/decrease, payload mismatch, frozen-account write blocking, audit writes, and unchanged CRMEB order/product/SKU/user boundary snapshots in isolated MySQL mode.
- Verification executed in this P1 closure: PHP 7.4 syntax passed for changed PHP files; `yfth_product_quota_contract_check.php` passed with 104 assertions; `yfth_product_quota_real_flow_check.php` passed in default source-guard mode and isolated MySQL 8.0.46 mode; MySQL 8.0.46 migration `run -> rollback -t 0 -> rerun -> duplicate run` passed on temporary database `yfth_product_quota_validation`; after rollback the five product quota tables and `yfth-product-quota*` permissions were removed; after rerun the five tables, 12 permissions, mandatory non-null idempotency/dedupe columns, and four unique guards were present; adjacent supply-chain, referral-reward, and franchise-opening contract checks passed; admin production build passed with existing CSS order, asset-size, and Browserslist warnings; uni-app request/context Node checks passed; H5 production build passed; mp-weixin production compile passed with existing skeleton `:key` and component-subpackage hints; `git diff --check main..HEAD` passed.
- CRMEB and financial boundary remains frozen: no CRMEB `store_order`, product stock, SKU stock, sales, order, payment, refund, user balance, points, brokerage, distribution, commission, withdrawal, settlement, or revenue-sharing writes.
- Still not implemented: purchase quota offset, reservation, consumption, reward conversion, opening auto-grant, purchase after-sale quota return, online payment, withdrawal, settlement, revenue sharing, product quota payment, production deployment, and production database migration.
- Production status: no production deployment, no production database connection, no production migration, and no server modification were performed.
- Next gate: run read-only architecture re-review for this P1 closure before any `main` merge decision.
- Final commit and validation results should be read from real Git status after this feature-branch commit.

## Current Fact Snapshot - Product Quota / Return Goods Quota Ledger V1

- Current development branch: `codex/yfth-product-quota-ledger-v1`.
- Start commit: `3ebd2135ef9d8146ad655c5965f63d134db9c6b5`.
- Scope: independent YFTH product-equivalent quota account, immutable quota ledger, headquarters manual grant workflow, manual correction, freeze/unfreeze/close, source snapshots, headquarters admin page/API, and franchisee/store-manager read-only miniapp display.
- New migration: `crmeb/database/migrations/20260711100000_create_yfth_product_quota_tables.php`.
- New tables: `yfth_product_quota_account`, `yfth_product_quota_ledger`, `yfth_product_quota_grant_order`, `yfth_product_quota_adjustment`, and `yfth_product_quota_source_snapshot`.
- New backend service: `app/services/yfth/ProductQuotaServices.php`.
- New user-token read-only APIs: `/api/yfth/product_quota/summary`, `/api/yfth/product_quota/account`, `/api/yfth/product_quota/account/:id`, and `/api/yfth/product_quota/ledger`.
- New headquarters admin-token APIs: `/adminapi/yfth/product_quota/*` for account, ledger, grant order, adjustment, freeze, unfreeze, and close operations.
- Frontend added: admin `template/admin/src/pages/yfth/productQuota/index.vue`; uni-app read-only pages under `template/uni-app/pages/yfth/product_quota/*`; store workbench links the quota entry only for `franchisee` and `store_manager`.
- Permission boundary: headquarters writes require admin token, CRMEB API permission, and headquarters scope; user reads resolve store through `CurrentBusinessContextServices` and allow only franchisee/store manager for their current store.
- Source boundary: V1 supports headquarters manual grants and manual `franchise_opening_initial_quota` grants only after revalidating opened franchise application, bound/verified store profile, active CRMEB store, and active store-bound identity grant. `referral_reward_converted` and `purchase_after_sale_return` are reserved and rejected in V1.
- Ledger boundary: amount fields use integer cents; balance changes lock the account row; grant transitions lock the grant row; duplicate grant/ledger/adjustment operations are guarded by unique keys and deterministic idempotency/dedupe keys.
- CRMEB and financial boundary: this V1 does not create CRMEB `store_order`, does not modify CRMEB product stock, SKU stock, sales, order, payment, or refund flows, and does not write user balance, points, brokerage, distribution, commission, withdrawal, settlement, or revenue-sharing data.
- Supply-chain/reward/opening boundary: this V1 does not offset purchase orders, reserve quota, consume quota, release quota, auto-convert rewards, auto-grant opening quota, or create purchase after-sale quota returns.
- UI boundary text: headquarters and miniapp pages label the module as product-equivalent quota only; it is not system payment, not extractable funds, and does not automatically offset purchase orders.
- Documentation added: `docs/YFTH_PRODUCT_QUOTA_ARCHITECTURE.md`.
- Tests added: `crmeb/tests/yfth_product_quota_contract_check.php` and `crmeb/tests/yfth_product_quota_real_flow_check.php`.
- Verification executed in this feature branch: PHP 7.4 syntax passed for changed PHP files; `yfth_product_quota_contract_check.php` passed with 88 assertions; `yfth_product_quota_real_flow_check.php` passed in default source-guard mode and in isolated MySQL 8.0.46 mode; MySQL 8.0.46 migration `run -> rollback -t 0 -> run` passed on a temporary local database after importing the CRMEB install schema; rollback removed all five product quota tables; rerun restored all five tables plus the account, ledger, grant, and adjustment uniqueness guards; adjacent supply-chain, referral-reward, and franchise-opening contract checks passed; admin production build passed with existing CSS order, asset-size, and Browserslist warnings; uni-app request/context Node checks passed; `git diff --check` passed with only line-ending warnings.
- Not implemented: purchase quota offset, reservation, consumption, reward conversion, opening auto-grant, after-sale quota return, online payment, withdrawal, settlement, revenue sharing, product quota payment, production deployment, and production database migration.
- Production status: no production deployment, no production database connection, no production migration, and no server modification were performed.
- Final commit and verification results should be read from real Git status after this feature-branch commit.

## Current Fact Snapshot - Final Referral Relationship And Read-only Reward Ledger V1 Closure

- Current branch after merge: `main`.
- Preserved feature branch: `codex/yfth-referral-reward-ledger-v1`.
- Main before merge: `6827cfdc6d1e2e06d59cb80b781bcfa4598da231`.
- Final reviewed feature commit: `786e5fe6d48cadf1e909cd7e56a4dfabd0c101b9`.
- Merge method: `git merge --ff-only codex/yfth-referral-reward-ledger-v1`.
- Architecture review conclusion: A, passed; no Blocker/P1/P2/P3 remains.
- Completed capabilities: referral code, referral candidate, referral event, C-side `package_5980` attribution, B-side `franchise_opening` attribution, immutable reward rule version, observing-period read-only ledger, ledger snapshot, offline settlement marker, append-only adjustment/reverse, user/franchisee read-only pages, and headquarters referral reward management page/API.
- New tables: `yfth_referral_code`, `yfth_referral_candidate`, `yfth_referral_event`, `yfth_referral_attribution`, `yfth_reward_rule_version`, `yfth_reward_rule_item`, `yfth_reward_ledger`, `yfth_reward_ledger_snapshot`, `yfth_reward_adjustment`, and `yfth_reward_settlement_record`.
- P1 closure: trusted business event resolver; true package activation/refund/close/freeze hooks; true `franchise_opened` hook; `ledger_unique_key` duplicate guard; observing scan business-state revalidation; published rule save forbidden.
- P2 closure: PHP 7.4 validation scripts fixed; contract check passed; isolated MySQL real-flow passed; franchise ledger assertions scoped by `scene + business_type + business_id`; H5/mp-weixin compile passed.
- CRMEB funding boundary: no `user_spread`, `user_brokerage`, `user_bill`, `now_money`, points, balance, commission, withdrawal, reward order, payment/refund mutation, or product/SKU stock/sales mutation.
- Package/franchise/supply-chain boundary: referral reward only consumes trusted events or read-only scans; it does not create package orders, mutate package/refund state, mutate franchise/opening state, create purchase orders, ship/receive inventory, or mutate inventory balances/ledgers.
- Verification: Architecture Auditor A review passed; PHP syntax passed; referral reward contract check passed; referral reward source guard passed; isolated MySQL referral reward real-flow passed; MySQL 8.0.46 migration run/rollback/rerun/duplicate run passed; package/franchise/supply-chain contract checks passed; admin build passed; uni-app Node checks passed; H5 production build passed; mp-weixin production compile passed; `git diff --check` passed.
- Not implemented: automatic payment, withdrawal, CRMEB distribution integration, online settlement, revenue sharing, product quota return, complex multi-level reward, production deployment, and production database migration.
- Production status: no production deployment, no production database connection, no production migration, and no server modification were performed.
- Final main and origin/main commit should be read from real Git HEAD after this documentation closure commit and push.

## Current Fact Snapshot - Referral Relationship And Read-only Reward Ledger V1

- Current development branch: `codex/yfth-referral-reward-ledger-v1`.
- Start commit: `6827cfdc6d1e2e06d59cb80b781bcfa4598da231`.
- Scope: independent YFTH referral code, referral candidate, referral event, attribution, immutable reward rule version, observing period, read-only reward ledger, headquarters offline settlement marker, append-only adjustment/reverse record, user/franchisee read-only display, and headquarters management page/API.
- New tables: `yfth_referral_code`, `yfth_referral_candidate`, `yfth_referral_event`, `yfth_referral_attribution`, `yfth_reward_rule_version`, `yfth_reward_rule_item`, `yfth_reward_ledger`, `yfth_reward_ledger_snapshot`, `yfth_reward_adjustment`, and `yfth_reward_settlement_record`.
- New backend service: `app/services/yfth/ReferralRewardServices.php`.
- New user-token APIs: `/api/yfth/referral/code`, `/api/yfth/referral/bind`, `/api/yfth/referral/candidates`, `/api/yfth/referral/ledger`, and `/api/yfth/referral/ledger/:id`.
- New headquarters admin-token APIs: `/adminapi/yfth/referral_reward/*` for rules, candidates, events, attribution, ledger, offline settlement marker, cancellation, reverse, and scan.
- Frontend added: admin `template/admin/src/pages/yfth/referralReward/index.vue`; uni-app pages under `template/uni-app/pages/yfth/referral/*`.
- C-side effective boundary: registration and payment are not final rewards; `package_activated` creates observing ledger, and scan promotes to valid only after the observing period.
- B-side effective boundary: application submitted, signed, and preparing are not final rewards; `franchise_opened` creates observing ledger, and scan promotes to valid only after the observing period.
- CRMEB funding boundary: this V1 does not write `user_spread`, `user_brokerage`, `user_bill`, `now_money`, points, balance, commission, withdrawal, CRMEB `store_order`, CRMEB order/payment/refund, or CRMEB product/SKU stock/sales.
- Package/franchise/supply-chain boundary: referral code reads trusted business events or scans only; it does not create package orders, change package/refund states, change franchise application/opening states, create purchase orders, ship/receive inventory, or write inventory balance/ledger.
- Audit and idempotency: unified audit writes to `yfth_audit_event` with domain `yfth_referral_reward`; referral events use `scene + event_type + idempotency_key`; ledgers use `scene + business_type + business_id + referrer_uid + rule_item_id`; snapshots are sanitized.
- Settlement boundary: `settled` means headquarters offline settlement marker only; it is not system payment and does not trigger withdrawal or balance changes.
- Documentation added: `docs/YFTH_REFERRAL_REWARD_ARCHITECTURE.md`.
- Tests added: `crmeb/tests/yfth_referral_reward_contract_check.php` and `crmeb/tests/yfth_referral_reward_real_flow_check.php`.
- P1 architecture-review closure in this round: `recordBusinessEvent()` no longer trusts client/caller supplied `referred_uid`, `candidate_id`, business snapshots, or arbitrary `source_type`; it now whitelists trusted package/franchise business events and derives the real referred uid from `yfth_package_purchase` / `yfth_package_instance` or `yfth_franchise_application` / opening grant / bound CRMEB store state.
- Real event hooks added: successful package activation emits `package_activated`; successful package refund/close/freeze emits the matching negative package event; final headquarters store-bound franchise identity grant emits `franchise_opened`. Hook failures are audited and do not roll back the original package or franchise operation.
- Duplicate ledger P1 closure: `yfth_reward_ledger` now has immutable `ledger_unique_key` with unique index `uniq_yfth_reward_ledger_unique_key`; `active_key` may be cleared for reversed/invalid rows without allowing the same business reward to be recreated.
- Observing scan P1 closure: scan revalidates the current package/franchise business state before promoting a ledger to `valid`; failed revalidation marks the ledger `invalid` with an append-only, deduped adjustment and audit event.
- P2 closure: admin rule save rejects direct `published` status via `reward_rule_save_published_forbidden`; rules must still go through the publish API. Reverse adjustments use deterministic `dedupe_key` values.
- P2 validation closure: PHP 7.4 string-literal syntax in `crmeb/tests/yfth_referral_reward_contract_check.php` and `crmeb/tests/yfth_referral_reward_real_flow_check.php` was corrected so the scripts run under the bundled PHP 7.4.33 runtime. `yfth_referral_reward_real_flow_check.php` now supports the documented `YFTH_REAL_FLOW_DB_HOST` / `PORT` / `NAME` / `USER` aliases in addition to the older `HOSTNAME` / `HOSTPORT` / `DATABASE` / `USERNAME` names.
- P2 test assertion closure: isolated MySQL real-flow package and franchise ledger assertions now use `scene + business_type + business_id` helper queries, preventing package/franchise auto-increment id collision or same-business-id scenarios from being miscounted. The real-flow script also asserts a package `business_id` does not pollute the franchise ledger query.
- Real-flow validation coverage added in this P2 closure: isolated MySQL mode now verifies package `package_activated` flow, duplicate package event idempotency, observing scan before/after due time, offline settlement marker, package refund reverse idempotency, invalid package scan void idempotency, franchise application before-open rejection, `franchise_opened` flow, duplicate franchise event idempotency, inactive grant invalidation, and CRMEB funding/order/stock boundary snapshots.
- Verification in this P2 closure: PHP 7.4 syntax passed for all changed PHP files in `main..HEAD`; `yfth_referral_reward_contract_check.php` passed with 81 assertions; `yfth_referral_reward_real_flow_check.php` passed in default source-guard mode and in isolated MySQL 8.0.46 mode; MySQL 8.0.46 migration `run -> rollback -t 0 -> run -> duplicate run` passed on temporary database `yfth_referral_reward_validation`; package/franchise-opening/supply-chain contract checks passed; `template/admin` production build passed with existing CSS order, asset-size, and Browserslist warnings; uni-app `yfth_multi_role_shell_contract_check.js` and `yfth_request_fallback_check.js` passed; H5 production build passed; mp-weixin production compile passed with existing skeleton `:key` and component-subpackage hints; `git diff --check main..HEAD` passed.
- Not implemented: automatic payment, withdrawal, CRMEB distribution integration, online settlement, revenue sharing, product quota return, complex multi-level reward, production deployment, and production database migration.
- Final commit and verification results should be read from real Git status after this feature-branch commit.

## Current Fact Snapshot - Final Franchise Contract Preparation Opening V1 Closure

- Current branch after merge: `main`.
- Preserved feature branch: `codex/yfth-franchise-contract-opening-v1`.
- Main before merge: `0dc2572dc445c8944983b2a4a3436571646cc5a1`.
- Final reviewed feature commit: `d62739059f044476260fe04994ce49a485fb3dc3`.
- Merge method: `git merge --ff-only codex/yfth-franchise-contract-opening-v1`.
- Architecture review conclusion: A, passed; no Blocker/P1/P2/P3 remains.
- Completed capabilities: offline franchise contract record, applicant contract confirmation, headquarters contract confirmation/signing, offline payment proof upload, finance confirmation/reject, store preparation profile, fixed preparation tasks, task evidence records, opening acceptance, acceptance items, controlled store-bound identity grant, user opening pages, and headquarters `franchise_opening` admin page.
- New tables: `yfth_franchise_contract`, `yfth_franchise_payment_proof`, `yfth_franchise_store_profile`, `yfth_franchise_preparation_task`, `yfth_franchise_preparation_task_record`, `yfth_store_opening_acceptance`, `yfth_store_opening_acceptance_item`, and `yfth_franchise_identity_grant`.
- P1 closure: user acceptance detail no longer implicitly creates acceptance records; finance confirmation no longer pre-creates acceptance; acceptance submit requires signed contract, finance-confirmed payment, complete fixed required tasks, and all required tasks approved; headquarters pass additionally requires verified/bound store profile, concrete active `system_store_id`, and active CRMEB store; acceptance passed does not automatically grant identity; identity grant still requires second headquarters confirmation.
- Identity boundary: final grant writes concrete store-bound `yfth_user_store_role` rows and does not create a global franchisee identity.
- Store boundary: user side cannot create or enable CRMEB `system_store`; headquarters must bind a valid `system_store_id` before operating rights can be granted.
- Supply-chain boundary: `first_purchase` only reads an existing YFTH purchase order and requires `stocked`; it does not create, audit, ship, receive, or mutate purchase orders, `yfth_inventory_balance`, or `yfth_inventory_ledger`.
- CRMEB boundary: this V1 does not create CRMEB `store_order`, does not modify CRMEB order/payment/refund main flows, does not modify CRMEB product stock, SKU stock, or sales, and does not write balance, points, brokerage, distribution, commission, settlement, or revenue-sharing data.
- Verification: Architecture Auditor A-level review passed; PHP syntax passed; franchise opening contract check passed; franchise opening real-flow source guard passed; MySQL 8.0.46 isolated migration `run -> rollback -t 0 -> run` passed; admin production build passed; existing uni-app request/context checks passed; `git diff --check` passed.
- Not implemented: real electronic signing, online franchise fee payment, CRMEB order/payment/refund integration for franchise fee, settlement, revenue sharing, recommendation rewards, product quota, procurement payment, purchase after-sale reversal, production deployment, and production database migration.
- Production status: no production deployment, no production database connection, no production migration, and no server modification were performed.
- Final main and origin/main commit should be read from real Git HEAD after this documentation closure commit and push.

## Current Fact Snapshot - Franchise Contract Preparation Opening V1

- Current development branch: `codex/yfth-franchise-contract-opening-v1`.
- Start commit: `0dc2572dc445c8944983b2a4a3436571646cc5a1`.
- Scope: offline franchise contract record, applicant contract confirmation, headquarters contract confirmation/signing, offline payment proof, finance confirmation/reject, store preparation profile, fixed preparation tasks, opening acceptance, and controlled store-bound identity grant.
- New migration: `crmeb/database/migrations/20260709100000_create_yfth_franchise_opening_tables.php`.
- New tables: `yfth_franchise_contract`, `yfth_franchise_payment_proof`, `yfth_franchise_store_profile`, `yfth_franchise_preparation_task`, `yfth_franchise_preparation_task_record`, `yfth_store_opening_acceptance`, `yfth_store_opening_acceptance_item`, and `yfth_franchise_identity_grant`.
- New backend service: `app/services/yfth/FranchiseOpeningServices.php`.
- New user-token APIs: `/api/yfth/franchise/opening/my`, contract detail/confirm, payment proof upload, preparation tasks, task submit, acceptance detail, and acceptance submit.
- New admin-token APIs: `/adminapi/yfth/franchise_opening/*` for contract, payment, store profile, task review, acceptance review, and identity grant.
- Application status boundary: Franchise Application V1 remains responsible up to `pending_contract`; this opening V1 alone advances `pending_contract -> signed -> preparing -> opened`.
- Identity boundary: final grant writes concrete store-bound `yfth_user_store_role` rows and `yfth_franchise_identity_grant`; no global franchisee identity is created.
- Store boundary: user side cannot create or enable CRMEB `system_store`; headquarters must bind an existing valid `system_store_id` before identity grant.
- Supply-chain boundary: first-purchase preparation can read an existing YFTH purchase order and require `stocked`; it does not create, audit, ship, receive, or mutate inventory.
- Frozen boundaries: no electronic signing, no online payment, no CRMEB `store_order`, no balance/points/brokerage/distribution/settlement writes, no CRMEB product/SKU stock or sales mutation, no recommendation reward, no product quota, no production deployment, and no production database migration.
- Documentation added: `docs/YFTH_FRANCHISE_OPENING_ARCHITECTURE.md`.
- Tests added: `crmeb/tests/yfth_franchise_opening_contract_check.php` and `crmeb/tests/yfth_franchise_opening_real_flow_check.php`.
- P1 hardening after architecture review: user acceptance detail no longer implicitly creates acceptance records or items; finance confirmation no longer pre-creates acceptance; acceptance can be created only inside the user submit action after the complete opening gate passes.
- Acceptance gate: application must be `preparing`, contract `signed`, payment `finance_confirmed`, store profile present, all fixed V1 required preparation tasks generated exactly once by task code, and every required task `approved`; `first_purchase` remains a read-only check against an existing `stocked` YFTH purchase order.
- Headquarters acceptance pass gate: repeats the full submit gate and additionally requires profile `verified` or `bound`, a concrete active `system_store_id`, and active CRMEB `system_store`.
- Identity boundary retained: acceptance `passed` does not automatically grant franchise/store identities; final identity grant still requires a second headquarters action and repeats signed contract, finance-confirmed payment, strict tasks-approved, passed acceptance, and bound active store checks.
- User field hardening: user-side opening writes reject client-submitted `uid`, `applicant_uid`, `status`, `store_id`, `system_store_id`, `finance_uid`, `verified_uid`, `reviewer_uid`, and `grant_uid`.
- Migration validation status for this P1 round: MySQL 8.0.46 isolated migration `run -> rollback -t 0 -> run` passed using a temporary local database imported from `crmeb/public/install/crmeb.sql`, temporary file cache `.env`, and a temporary `php.ini` with `pdo_mysql`; after run/rerun the 8 opening tables, key unique indexes, and `yfth-franchise-opening-index` permission existed, and after rollback the opening tables and permission were removed.
- Final commit and verification results should be read from real Git status after this feature-branch commit.

## Current Fact Snapshot - Final Supply Chain And Store Inventory V1 Closure

- Current branch after merge: `main`.
- Preserved feature branch: `codex/yfth-supply-chain-inventory-v1`.
- Main before merge: `fc001260ff56dfc7b4a6a39358cebb612f9f4131`.
- Final reviewed feature commit: `effbd26bdfa9bfc86be5122146885ca3368719c5`.
- Final merged feature commit: `5c02f429c2a10c1eb2534cb1e4432532d9399bab`.
- Merge method: `git merge --ff-only codex/yfth-supply-chain-inventory-v1`; no merge commit, squash, rebase, or history rewrite was used.
- Architecture review conclusion: A, passed; no Blocker/P1/P2 remains for Supply Chain And Store Inventory V1.
- P3 EOF whitespace cleanup commit: `5c02f429c2a10c1eb2534cb1e4432532d9399bab`.
- Verification fact: Architecture Auditor completed PHP/MySQL 8.0.46 isolated validation at `effbd26bdfa9bfc86be5122146885ca3368719c5`; `5c02f429c2a10c1eb2534cb1e4432532d9399bab` only removes trailing EOF blank lines and does not change business logic, permission rules, state transitions, API behavior, or migration structure.
- Completed capabilities: headquarters supply catalog, store purchase order, headquarters audit, headquarters shipment, store receipt and stock-in, store inventory balance, immutable inventory ledger, inventory alert rules, headquarters admin pages, and mobile store purchase/inventory entry.
- New tables: `yfth_supply_catalog`, `yfth_purchase_order`, `yfth_purchase_order_item`, `yfth_stock_location`, `yfth_inventory_balance`, `yfth_inventory_ledger`, `yfth_purchase_shipment`, `yfth_purchase_receipt`, and `yfth_inventory_alert_rule`.
- P1 closure retained in main: shipment and receipt lock the purchase order row inside the transaction; duplicate shipment returns the existing shipment; duplicate receipt returns the existing receipt; inventory is not increased repeatedly; `store_purchase` capability is mandatory for purchase writes; unique constraints guard duplicate shipment, receipt, and ledger rows.
- Boundary: this V1 does not create CRMEB `store_order` rows, does not modify CRMEB product stock, SKU stock, or sales, does not modify CRMEB order/payment/refund main flows, and does not write balance, points, brokerage, distribution, commission, settlement, or revenue-sharing data.
- Not implemented: procurement payment, product quota, recommendation rewards, revenue sharing, settlement, procurement after-sale reversal, multi-shipment/partial receiving, CRMEB consumer order auto-deducting store inventory, production deployment, and production database migration.
- Production status: no production deployment, no production database connection, no production migration, and no server modification were performed in this closure.
- Final main and origin/main commit should be read from real Git HEAD after this documentation closure commit and push.

## Current Fact Snapshot - Supply Chain And Store Inventory V1

- Current development branch: `codex/yfth-supply-chain-inventory-v1`.
- Start commit: `fc001260ff56dfc7b4a6a39358cebb612f9f4131`.
- Scope: headquarters supply catalog, store purchase order, headquarters audit and shipment, store receipt and stock-in, store inventory balance, immutable inventory ledger, and inventory alert rules.
- New backend service: `app/services/yfth/SupplyChainServices.php`.
- New user-token controller: `app/api/controller/v1/yfth/SupplyChainController.php`.
- New admin-token controller: `app/adminapi/controller/v1/yfth/SupplyChain.php`.
- New migration: `crmeb/database/migrations/20260708170000_create_yfth_supply_chain_inventory_tables.php`.
- New tables: `yfth_supply_catalog`, `yfth_purchase_order`, `yfth_purchase_order_item`, `yfth_stock_location`, `yfth_inventory_balance`, `yfth_inventory_ledger`, `yfth_purchase_shipment`, `yfth_purchase_receipt`, and `yfth_inventory_alert_rule`.
- User APIs added under CRMEB user token: `/api/yfth/supply/catalog`, `/api/yfth/supply/purchase_order`, `/api/yfth/supply/purchase_order/:id`, `/api/yfth/supply/in_transit`, `/api/yfth/supply/purchase_order/:id/receive`, `/api/yfth/supply/inventory`, and `/api/yfth/supply/ledger`.
- Admin APIs added under admin token and CRMEB API permission checks: `yfth/supply_chain/catalog`, `catalog/save`, `catalog/disable`, `product/search`, `purchase_order`, `purchase_order/<id>`, `purchase_order/<id>/audit`, `purchase_order/<id>/ship`, `shipment`, `inventory`, `ledger`, `alert_rule`, and `alert_rule/save`.
- Permission boundary: `franchisee` and `store_manager` can create purchase orders and confirm receipt; `store_staff` can only read catalog, purchase order state, inventory, and ledger.
- Store isolation: write bodies containing client-submitted `store_id`, `store_ids`, role, or operator fields are rejected. Store-side list/detail/inventory queries use the store resolved by `CurrentBusinessContextServices`.
- Product boundary: supply catalog references CRMEB `store_product`; purchase items reference CRMEB `store_product_attr_value.unique`. No second product library was added.
- Inventory boundary: YFTH store inventory is independent from CRMEB consumer sales stock. This V1 does not modify CRMEB `store_product.stock`, SKU stock, sales, or quota fields.
- Order boundary: purchase orders are not CRMEB `store_order` rows and do not write CRMEB order, payment, refund, user balance, brokerage, or points data.
- Frontend added: admin `pages/yfth/supplyChain/index.vue`; uni-app purchase center `pages/yfth/workbench/purchase/index.vue`, purchase detail, and inventory pages; the store workbench now links to purchase inventory.
- Documentation added: `docs/YFTH_SUPPLY_CHAIN_INVENTORY_ARCHITECTURE.md`.
- Test added: `crmeb/tests/yfth_supply_chain_contract_check.php`.
- Architecture review P1 closure completed on this branch after the initial C review: shipment and receipt transitions now lock the purchase order row and re-check status inside the transaction; repeated shipment returns the existing shipment; repeated receipt returns the existing receipt and does not write additional inventory balance or ledger rows.
- New uniqueness guards added in the V1 migration: `uniq_yfth_purchase_item_order_sku`, `uniq_yfth_purchase_shipment_order`, `uniq_yfth_purchase_receipt_order`, `uniq_yfth_purchase_receipt_shipment`, and `uniq_yfth_inventory_ledger_business_sku`.
- Store purchase writes now require explicit `store_purchase` capability. Empty capability lists no longer allow purchase order creation or receipt stock-in.
- P2 closure in this round: purchase amounts are calculated with integer cents instead of PHP float math; `allow_store_types` matching uses exact `FIND_IN_SET` token matching; catalog updates preserve `created_uid` and `create_time`.
- Real-flow test added: `crmeb/tests/yfth_supply_chain_real_flow_check.php`. It performs P1/P2 source guards by default and can run duplicate shipment, duplicate receipt, and duplicate ledger uniqueness checks against isolated MySQL when the documented execution environment variables are set.
- Verification executed before the P1 closure in this feature branch: PHP syntax passed for changed backend/migration/test files; `crmeb/tests/yfth_supply_chain_contract_check.php` passed with 50 assertions; isolated MySQL 8.0.46 full migration `run -> rollback -t 0 -> run` passed after importing the CRMEB install schema into a temporary database, with `eb_yfth_purchase_order` and the `yfth-supply-chain-index` admin permission present after rerun and absent after rollback; admin Vue production build passed to a temporary output directory with existing CSS order, asset-size, and Browserslist warnings; uni-app executable project checks `yfth_multi_role_shell_contract_check.js` and `yfth_request_fallback_check.js` passed.
- Verification executed during the P1 closure in the current local environment: `git diff --check` passed with only line-ending warnings; bundled Node ran `template/uni-app/tests/yfth_multi_role_shell_contract_check.js` and `template/uni-app/tests/yfth_request_fallback_check.js` successfully; an additional Node source-guard check confirmed row locks, strict `store_purchase` capability, deterministic receipt idempotency, no float purchase amount calculation, exact store-type matching, catalog creation-field preservation, and all new uniqueness indexes. PHP CLI and isolated MySQL were not available in the current shell, so `php crmeb/tests/yfth_supply_chain_contract_check.php`, `php crmeb/tests/yfth_supply_chain_real_flow_check.php`, and migration rerun/rollback were not re-executed in this P1 closure round.
- Production deployment, production database migration, and production data access were not performed.
- Not implemented in V1: procurement payment, complex financial settlement, recommendation reward, revenue sharing, CRMEB consumer order fulfillment integration, consumer purchase auto-deducting store inventory, returns/reversals, and partial multi-shipment receiving.

## Current Fact Snapshot - Franchise Application Workflow V1 Closure

- Current branch: `main`.
- Preserved feature branch: `feature/yfth-franchise-application-v1`.
- Main before merge: `15143d4a6a28e07b606ba4e934a5f3c31c63ae36`.
- Final reviewed feature commit: `666c2f2e11b5dbf7ba940afb6b8d5c687233c842`.
- Merge method: `git merge --ff-only feature/yfth-franchise-application-v1`; no merge commit, squash, rebase, or history rewrite was used.
- Architecture review conclusion: B, allowed to merge; remaining P2 items are retained for later and do not block the current merge.
- Franchise Application Workflow V1 is completed and merged into `main`.
- Scope completed: first headquarters franchise application workflow only: user application submission, headquarters list/detail, owner assignment, status advancement, follow records, user-side progress lookup, permissions, audit, migration, and minimal real pages.
- New tables: `yfth_franchise_application` and `yfth_franchise_follow_record`.
- Identity boundary: applicant identity remains CRMEB `user.uid`; an application is not a franchisee identity, not a store, not a contract, and not an account grant.
- User API boundary: user-token routes read the applicant from `Request::uid()` and reject client-submitted `uid`, `applicant_uid`, `assigned_uid`, `status`, and `store_id`.
- Admin API boundary: headquarters management uses adminapi routes, `AdminAuthTokenMiddleware`, `AdminCheckRoleMiddleware`, and explicit `SystemRoleServices::assertApiAuthForAdmin()` checks.
- Status model implemented in V1: `submitted -> contacting -> communicating -> inspecting -> pending_contract`; later `signed`, `preparing`, `opened`, and `terminated` states are reserved and not opened by the V1 API.
- Frontend added: uni-app pages `pages/yfth/franchise/index`, `pages/yfth/franchise/apply`, `pages/yfth/franchise/detail`, a user-center cooperation entry, and admin page `template/admin/src/pages/yfth/franchiseApplication/index.vue`.
- Audit: unified YFTH audit writes to `yfth_audit_event` with domain `yfth_franchise_application` for submit, owner assignment, status changes, and follow creation.
- P1 closure in this round: headquarters application detail now reads audit event time from real `yfth_audit_event.add_time`; no `create_time` field was added to the audit table.
- P2 closure in this round: follow records now carry `visible_type = internal | public`; headquarters defaults new follow records to `internal`, while user-side detail returns only `public` records.
- P2 closure in this round: application audit history lookup now matches exact `object_type/object_id` pairs and follow-record ids; it no longer uses `after_state LIKE application_id`, preventing `application_id = 1` from matching `10`.
- Documentation added: `docs/YFTH_FRANCHISE_APPLICATION_ARCHITECTURE.md`.
- Completed capabilities: user franchise application, headquarters franchise management backend, status workflow, owner assignment, follow records, unified audit, user-token permission boundary, and headquarters admin permission boundary.
- Verification executed in this feature branch: PHP syntax passed for changed backend/migration/test files; the P1/P2 closure `yfth_franchise_application_contract_check.php` passed with 163 assertions; isolated MySQL 8.0.46 migration `up/down/up` passed for `20260708110000_create_yfth_franchise_application_tables.php` plus `20260708113000_add_yfth_franchise_follow_visibility.php`, including `visible_type`, `idx_yfth_franchise_follow_visible_time`, and seeded franchise-application menu/API permissions; isolated MySQL 8.0.46 service-level detail validation passed for `adminDetail()` with/without audit records, exact audit exclusion of application `10` while reading application `1`, `add_time` audit DTOs, `assignOwner()`, `changeStatus()`, and `addFollow()`; admin production build passed with existing CSS order, asset-size, and Browserslist warnings; uni-app H5 production build passed with 350 files / 11,140,968 bytes; mp-weixin production compile passed with 1,145 files / 7,653,679 bytes and existing CRMEB skeleton key/component placement warnings.
- Explicitly not implemented: electronic contracts, online signing, franchise fee payment, store creation, store decoration/opening acceptance tasks, recommendation rewards, procurement, inventory, product quota, settlement, revenue sharing, distribution rebate, and production deployment.
- Not modified: CRMEB user/login/order/payment/refund core flows, 5980 package activation, service appointment state machine, service writeoff state machine, store workbench adapter, and existing customer CRM attribution model.
- Branch handling: local and remote feature branches are retained for stage history; no branch was deleted.
- Production status: no production deployment, no production database connection, no server modification, no WeChat upload.
- Historical note: older unfinished-module sections that listed franchise application as future work are superseded by this V1 closure snapshot for the basic application workflow only; franchise contract/payment/opening and settlement modules remain future work.
- Final `main` and `origin/main` commit should be read from real Git HEAD after this documentation closure commit and push.
- Next business module should be decided separately by the project controller.

## Current Fact Snapshot - Franchise Customer CRM V1 Closure

- Current branch: `main`.
- Preserved feature branch: `feature/yfth-franchise-crm-v1`.
- Main before merge: `99c9d96b3bdbd8801e9069d714ed883858f57f51`.
- Final reviewed feature commit: `ff166cc3f8e39565476a06f85ce71577ed88b131`.
- Merge method: `git merge --ff-only feature/yfth-franchise-crm-v1`; no merge commit, squash, rebase, or history rewrite was used.
- Architecture review conclusion: B, allowed to merge; P2 items are retained for later and do not block the current merge.
- Franchise Customer CRM V1 is completed and merged into `main`.
- Completed capabilities: CRMEB `user.uid` reuse, `yfth_customer_relation`, `yfth_customer_follow_record`, customer attribution, customer list, customer detail, follow records, trusted source attribution, `order` / `appointment` / `writeoff` source binding, store isolation, user-token API, data masking, and unified YFTH audit.
- Security closure retained in `main`: direct client-submitted `uid`, `owner_uid`, or binding-body `store_id` is forbidden; customer relation binding must resolve the real customer from a trusted same-store business source.
- Branch handling: local and remote feature branches are retained for stage history; no branch was deleted.
- Production status: no production deployment, no production database connection, and no server modification was performed in this closure.
- Non-blocking P2 retained for later: before production release, add a complete isolated smoke run for this merged `main`; before production release, add a browser role-by-role smoke walk-through against a real local backend.
- Still out of scope: franchise application, recommendation rewards, procurement, inventory replenishment, product quota, settlement, revenue sharing, distribution rebate, franchise contracts, order/payment/refund changes, package changes, appointment/writeoff state-machine changes, and production deployment.
- Final `main` and `origin/main` commit should be read from real Git HEAD after this documentation closure commit and push.
- Next business module should be decided separately by the project controller.

## Current Fact Snapshot - 2026-07-07 Franchise Customer CRM V1 P1 Closure

- Current branch: `feature/yfth-franchise-crm-v1`.
- Start baseline: `main` / `origin/main` at `99c9d96b3bdbd8801e9069d714ed883858f57f51`.
- Latest commit for this round should be read from real Git HEAD after the feature branch commit and push.
- Scope: customer relationship foundation for the franchisee/store operation loop, plus the P1 attribution-security closure after architecture review.
- Completed in this round: customer attribution relation, current-store customer list, customer detail, customer operating status display, trusted customer source display, customer follow records, and P1 secure attribution binding.
- P1 root cause closed: the previous binding path could create an active relation from a client-submitted CRMEB `uid`. This is now forbidden.
- Secure binding model: `POST /api/yfth/customer/relation` accepts only trusted business sources: `source = order | appointment | writeoff` and `reference_id`. The service resolves the real customer `uid` from the same-store order, appointment, or writeoff record.
- Deprecated/forbidden body fields for binding: `uid`, `owner_uid`, and `store_id`. If submitted, the API rejects the request with `direct_customer_binding_forbidden`.
- Same-store rules: order sources must be paid, main orders and not deleted; appointment sources must belong to the current store and not be cancelled/rejected; writeoff sources must belong to the current store and be `succeeded`.
- Cross-store source protection: a store operator cannot bind a customer from another store's order, appointment, or writeoff record; cross-store sources are rejected and do not create relation rows.
- Existing active attribution protection: if the customer already has an active relation, binding returns `already_bound`; another store cannot take over the active customer.
- Stable CRMEB user identity is reused: customer identity remains `user.uid`; no new user, login, member, or account system was introduced.
- New database tables: `yfth_customer_relation` and `yfth_customer_follow_record`.
- Active attribution uniqueness: `yfth_customer_relation.active_key` has a unique index and active relations use the customer `uid` as the active key, preventing one customer from being actively owned by multiple stores in V1; `source + reference_id` is indexed for trusted source lookup/audit.
- User-token API only: `/api/yfth/customer/list`, `/api/yfth/customer/relation`, `/api/yfth/customer/:id`, and `/api/yfth/customer/:id/follow` are registered under `AuthTokenMiddleware`.
- Permission boundary: `franchisee`, `store_manager`, and `store_staff` can use the V1 customer module for the current authorized store; normal customer and `service_mentor` contexts are rejected. The server resolves role and store through `CurrentBusinessContextServices`; frontend `store_id` is not trusted as authorization.
- Data isolation: customer detail and follow writes load by `customer_relation_id + current store_id + active status`, not by global uid lookup.
- DTO safety: list/detail responses expose nickname, avatar, `phone_masked`, trusted source, customer status, package/service status, and follow timestamps only. They do not return `uid`, `store_id`, `owner_uid`, `bind_time`, `create_time`, `update_time`, internal relation `status`, full phone, address, ID card, openid, unionid, payment information, or internal token fields.
- Audit: attribution binding and follow creation write through `AuditEventServices` into `yfth_audit_event` with domain `yfth_franchise_customer`.
- uni-app pages added: `pages/yfth/workbench/customer/index`, `pages/yfth/workbench/customer/detail`, and `pages/yfth/workbench/customer/follow`; `workbench/index.vue` only links to the module and is not expanded into a large CRM page.
- Documentation added: `docs/YFTH_FRANCHISE_CUSTOMER_ARCHITECTURE.md`.
- Verification executed for this P1 closure: PHP syntax passed for changed PHP/migration/test files; `yfth_franchise_customer_contract_check.php` passed with 123 assertions; isolated MySQL 8.0.46 migration run/rollback/rerun passed for `20260707110000_create_yfth_customer_relation_tables`; `reference_id`, `uniq_yfth_customer_relation_active`, and `idx_yfth_customer_relation_source_ref` were verified; `yfth_franchise_customer_real_flow_check.php` passed against temporary database `yfth_franchise_crm_p1_validation`, file cache, temporary local API server, and temporary CRMEB user tokens; Node checks `yfth_multi_role_shell_contract_check.js` and `yfth_request_fallback_check.js` passed.
- Real flow coverage in the P1 closure: naked uid binding rejected with no relation row; same-store order binding succeeded; cross-store order rejected; same-store appointment binding succeeded; cross-store appointment rejected; already-bound customer takeover rejected; customer and `service_mentor` denied; `store_staff`, `store_manager`, and `franchisee` allowed in their scoped flows; audit record inserted into `yfth_audit_event`; DTO checks confirmed no full phone, address, openid, unionid, `owner_uid`, internal timestamps, internal status, or payment fields are returned.
- Still out of scope: recommendation rewards, distribution rebate, franchise contracts, procurement, inventory replenishment, product quota, settlement, revenue sharing, supply chain, activity split, customer transfer, and production deployment.
- Not modified: CRMEB login core, orders, payment, refund, 5980 package activation, service appointment state machine, service writeoff state machine, headquarters admin productization, and production configuration.
- Production status: no production database connection, no production server deployment, and no WeChat upload has been performed.
- Next step after this P1 fix: run a read-only architecture re-review before any main merge decision.

## Current Fact Snapshot - 2026-07-07 Final Store Workbench Business Adapter V1 Closure

- Current branch: `main`.
- Preserved feature branch: `feature/yfth-store-workbench-business-adapter-v1`.
- Main before merge: `dc5efcc6cd3b9e9131a59f48e6a8e7718ec933bb`.
- Final reviewed feature commit: `4e20063730a039e6383b17b366f3211fdc132b16`; do not use `6ca24c882a3cd73e5babd63e169a7e85005e64e7` as a merge target because it did not include the cross-store writeoff-result P1 fix.
- Merge method: `git merge --ff-only feature/yfth-store-workbench-business-adapter-v1`; no merge commit, squash, rebase, or history rewrite was used.
- Architecture review conclusion: B, allowed to merge; no current Blocker/P1 remains for Store Workbench Business Adapter V1.
- Completed stable capabilities now merged into `main`: CRMEB user-token store workbench, store appointment management, service writeoff, store order read-only lookup, explicit `yfth_operator_context`, server-side store isolation, and `admin_token` isolation.
- Store workbench result lookup P1 remains closed in `main`: same-store staff/manager/franchisee can read same-store writeoff result, same-store unwritten appointments return `status = none`, and cross-store appointment-id result reads are rejected without business writes.
- Branch handling: local and remote feature branches are retained for stage history; no branch was deleted.
- Production status: no production deployment, no production database connection, no server modification, and no WeChat upload was performed in this closure.
- Non-blocking P2 retained for later: before production release, add a complete isolated smoke run for this merged `main`; before production release, add a browser role-by-role smoke walk-through against a real local backend.
- P2 status: both P2 items are non-blocking for the current merge and should be handled together in the later release-readiness pass.
- Still out of scope: procurement, inventory replenishment, product quota, recommendation rewards, franchise contracts, mentor real business workflows, settlement, revenue sharing, order/payment/refund changes, appointment/writeoff state-machine redesign, and production deployment.
- Final `main` and `origin/main` commit should be read from real Git HEAD after this documentation closure commit and push.
- Next business module should be decided separately by the project controller.

## Current Fact Snapshot - 2026-07-06 Store Workbench Business Adapter V1

- Current branch: `feature/yfth-store-workbench-business-adapter-v1`.
- Start baseline: `main` / `origin/main` at `dc5efcc6cd3b9e9131a59f48e6a8e7718ec933bb`.
- Latest commit for this round should be read from real Git HEAD after the feature-branch commit.
- Scope: formal store-scoped user-token business adapter for the multi-role miniapp workbench, plus the P1 runtime and permission-boundary closure requested before architecture audit.
- Completed real adapters: store appointment management, service writeoff, and store order read-only lookup.
- Appointment capability: store appointment list/detail; `franchisee` and `store_manager` can confirm, reject, and cancel within authorized stores; `store_staff` remains read/writeoff-oriented and cannot configure or change appointment state.
- Writeoff capability: token/digital precheck, token/digital writeoff, writeoff result lookup, and writeoff record list/detail reuse the existing Service Appointment Writeoff V1 service and idempotency/audit behavior.
- P1 cross-store writeoff result leak is closed: `GET /api/yfth/store_workbench/writeoff/result/:id` now resolves the user-token store scope, delegates to `ServiceAppointmentWriteoffServices::writeoffResultForAppointmentByStoreOperator()`, checks the appointment store before returning `none` or a writeoff result, re-checks the succeeded record's store before formatting it, and never calls the legacy unscoped result method from the store workbench adapter.
- Writeoff result behavior after the P1 fix: same-store staff/manager/franchisee can read same-store writeoff result; same-store unwritten appointment returns `status = none`; cross-store staff/manager/franchisee, customer, service mentor, revoked identity, disabled-store role, and missing appointment are rejected; cross-store read attempts leave appointment, code, benefit, event, audit, and writeoff-record snapshots unchanged.
- Store order capability: real CRMEB store order list/detail lookup is read-only, store-scoped, and masks customer contact/address fields for the workbench.
- Security boundary: miniapp uses only the CRMEB user token; the workbench does not expose, reuse, persist, or request `admin_token`.
- Server-side validation: every request resolves the current YFTH role/store through `CurrentBusinessContextServices`; client role/store values are only requested context and are not trusted as authorization.
- The previous "admin-compatible store scope" wording is now closed: store workbench calls pass an explicit `yfth_operator_context` with `operator_type = user_store_role`, `operator_uid`, `role_code`, `store_id`, `authorized_store_ids`, and `allowed_actions`. They do not forge a backend admin id, admin role, super-admin state, or `adminInfo`.
- Reused stable services: existing appointment booking service, writeoff service, YFTH identity/store role services, and CRMEB store order DAOs. The booking/writeoff services now expose store-operator entry methods that delegate into the same core transaction/state-machine paths used by backend admin entry points. No duplicate appointment/writeoff/order state machine was introduced.
- New/updated backend files: `StoreWorkbenchController`, `StoreWorkbenchBusinessAdapterServices`, `AdminStoreContextServices`, `ServiceAppointmentBookingServices`, `ServiceAppointmentWriteoffServices`, `yfth_store_workbench_adapter_contract_check.php`, and `yfth_store_workbench_adapter_real_flow_check.php`.
- New frontend behavior: `pages/yfth/workbench/index.vue` now shows real overview, appointment, writeoff, and read-only order modules through `api/yfth.js`.
- Documentation: `docs/YFTH_STORE_WORKBENCH_ADAPTER_ARCHITECTURE.md` records adapter boundaries and role rules; `docs/YFTH_MINIAPP_MULTI_ROLE_ARCHITECTURE.md` marks the previous shell limitation as historical; `docs/YFTH_STORE_WORKBENCH_RUNTIME_VALIDATION.md` records the isolated runtime validation facts.
- Real runtime validation: an isolated MySQL 8.0.46 database, isolated Redis 5.0.14.1 DB, temporary CRMEB API server at `http://127.0.0.1:18081`, temporary CRMEB user-token fixtures, and real HTTP requests through route, middleware, controller, context, adapter, service, and MySQL were used. No production `.env`, production database, production Redis, production user data, server deployment, or WeChat upload was used.
- Runtime coverage: customer and service mentor forbidden; staff read-only appointment access plus writeoff allowed; staff appointment writes forbidden; store manager confirm/reject/cancel verified with database state changes; franchisee A/B store switching and C/all-store denial verified; revoked identity and disabled-store role forbidden; cross-store appointment, writeoff, and order access denied; digital and QR writeoff are idempotent and consume benefit/writeoff/appointment completion exactly once; user-token headquarter exception writeoff route is unavailable.
- P1 re-validation on 2026-07-07: the store-workbench real-flow script was rerun against an isolated MySQL 8.0.46 database named `yfth_storewb_validation_*`, temporary local PHP API server `http://127.0.0.1:18121`, and file cache driver. Redis probe was not executed in this P1 rerun because the local portable PHP runtime did not load a Redis extension; the route, middleware, controller, adapter, service, and MySQL path were still exercised through real HTTP.
- Store order validation: order list/detail are scoped to the resolved store, return only whitelist fields, mask name/phone/address values, exclude internal payment/refund/admin/token/idempotency fields, and remain read-only against order/payment/refund/shipment/inventory/commission/user-balance snapshots.
- Frontend/build validation in this round: PHP syntax checks passed for changed backend files; `yfth_store_workbench_adapter_contract_check.php`, `yfth_service_appointment_contract_check.php`, `yfth_multi_role_shell_contract_check.js`, and `yfth_request_fallback_check.js` passed; H5 development compilation reached the local dev server startup on `http://localhost:8080/` and was stopped manually; H5 production build passed; mp-weixin production compile passed. A role-by-role browser walk-through against the live local backend was not executed in this closure; the true backend path was verified by real HTTP tests.
- No database migration was added in this round.
- Not modified: CRMEB login core, admin-token login, orders/payment/refund core flows, 5980 package activation, appointment state-machine internals, writeoff state-machine internals, database migrations, and production configuration.
- Still not implemented: procurement, inventory replenishment, product quota, franchise contracts, recommendation rewards, mentor real business workflows, settlement, revenue sharing, store order mutation/fulfillment/refund/shipment, and production deployment.
- Historical pre-merge status for this 2026-07-06 section: at that time, the feature branch was not merged into `main` and the push target was the feature branch only.
- Historical pre-merge gate for this 2026-07-06 section: a read-only architecture audit was still required then. The later 2026-07-07 closure snapshot above records the completed B review and fast-forward merge into `main`.

## Current Fact Snapshot - 2026-07-05 Final Multi-role Miniapp Shell V1 Closure

- Current branch: `main`.
- Preserved feature branch: `feature/yfth-miniapp-multi-role-shell-v1`.
- Preserved static demo branch: `feature/yfth-multi-role-interaction-demo-v1`.
- Main before merge: `f30426c955cce55cc552f474782c880034986514`.
- Feature branch final reviewed commit: `e4d36b519eac017bfaba5bd19dcb463480e861a0`.
- Merge method: `git merge --ff-only feature/yfth-miniapp-multi-role-shell-v1`.
- Final architecture re-review conclusion: B, no Blocker/P1, allowed to merge; P2 items are recorded for later handling.
- Stable capabilities now merged into `main`: CRMEB customer storefront continues to use the page-decoration system; user-center business workbench entry; `franchisee`, `store_manager`, `store_staff`, and `service_mentor` shell roles; role/store context; UID-bound YFTH context cache; customer token and `admin_token` isolation; direct workbench access blocking without business identity; H5 development build; H5 production build; mp-weixin production compile; H5 HTML fallback safety boundary; user-center business-entry lifecycle fix; footer request-failure cache tolerance.
- Still not open in this V1: user-token store writeoff, user-token store orders, user-token appointment management, procurement, inventory replenishment, product quota, franchise contracts, recommendation rewards, real mentor business flows, real settlement, and revenue sharing.
- Not modified by this closure: CRMEB login core, orders, payment, refund, 5980 package activation, service appointment state machine, and writeoff state machine.
- Production status: no production database connection, no production server deployment, and no WeChat platform upload.
- Final `main` and `origin/main` commit should be read from real Git HEAD after push, avoiding a self-referential document hash.
- Next business module is to be decided separately by the project controller.

### Non-blocking P2 Recorded For Later

- P2-1: final review recorded that role-switch request failure may only show an error and not immediately clear every local YFTH context path; workbench entry still performs server-side context validation, so this does not create permission bypass. Keep this for the later real business-side adapter/error-flow pass and do not expand scope in this closure round.
- P2-2: H5 non-200 HTML responses are no longer forged into successful JSON, but HTML classification currently happens before the HTTP-error branch. An HTML 401 may not always trigger the original `toLogin()` flow, and the rejected error may not preserve the full HTTP status. Keep this for a later unified request-layer error-governance pass and do not expand scope in this closure round.
- Both P2 items are non-blocking for the Multi-role Miniapp Shell V1 merge.

## Current Fact Snapshot - 2026-07-05 Multi-role Miniapp Shell P1 Audit Fix

- Current branch: `feature/yfth-miniapp-multi-role-shell-v1`.
- Round start commit: `cb4fc30a8ecc782f1852c898f26fdda2208dea66`; stable `main` / `origin/main`: `f30426c955cce55cc552f474782c880034986514`.
- Current latest commit: use Git HEAD after the P1 fix commit; this branch remains a feature branch and is not merged into `main`.
- Audit result before this round: C, not allowed to merge. This round closes P1-1 and P1-2 and addresses the recorded P2 footer tolerance issue; a follow-up read-only architecture re-review is still required before any main merge decision.
- P1-1 root cause: the H5 request layer converted arbitrary HTML responses into `{ status: 200, data: ... }`, which could mask login expiry, permission denial, gateway errors, PHP errors, or other non-JSON API failures.
- P1-1 fix: the request layer now uses `utils/yfthH5Fallback.js`; HTML fallback is allowed only for H5 development, HTTP 200, local devServer origin, confirmed full HTML, and explicit local-dev whitelist endpoints. Production H5, non-200 HTML, and non-whitelisted HTML now reject through the normal error flow. `/api/get_script` ignores full HTML only in local H5 development and still honors HTTP status.
- P1-2 root cause: `pages/user/index.vue` did not reliably refresh the YFTH business identity entry on normal `onShow()` user-center entry.
- P1-2 fix: logged-in `onShow()` and `onLoadFun()` now refresh user info first, reset the entry to false before async loading, wait for a current UID, and guard async identity responses with a request sequence and UID comparison. Logout, request failure, revoked identity, and user switch paths hide the entry.
- P2 footer fix: `components/pageFooter/index.vue` no longer clears a valid current/cached footer when version or navigation requests fail. Explicit successful empty config can still hide the footer; no-cache failure remains a safe hidden state.
- Additional compatibility fix: workbench identity list keys now use `identity_key` instead of non-H5 `:key` expressions, and role-switch request failure clears YFTH context and safely returns to the customer side.
- Verification added/updated: `template/uni-app/tests/yfth_request_fallback_check.js` and `template/uni-app/tests/yfth_multi_role_shell_contract_check.js`.
- Verified results in this round: request fallback scenarios passed; contract check passed; HBuilderX Babel parser passed for changed JS/Vue scripts; H5 development server opened successfully; H5 production build passed; mp-weixin production compile passed with Node 18 plus `--no-opt` after Node/V8 optimization crashes were reproduced without that flag; browser validation covered customer home, user center, workbench direct access, role switch, store switch, mocked user-center business roles, request-failure hidden entry, and footer cached failure preservation.
- Browser notes: the only observed network noise was DCloud analytics `ERR_ABORTED` and the intentional mocked 500 in request-failure/footer tests; there were no page errors in the passing browser scenarios.
- Production status: no production server, production database, real AppID/AppSecret/private key, WeChat upload, or deployment was used.
- Current frozen scope remains unchanged: do not develop procurement, rewards, contracts, inventory, real settlement, appointment/writeoff state machines, CRMEB login core, payment, refund, package activation, production deployment, or main merge in this round.

- 项目名称：御方通和加盟 APP / 微信小程序
- 当前代码基础：CRMEB 开源商城 PHP 版 v5.6 系列
- 本地路径：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform`
- GitHub 仓库：`https://github.com/Flippede/yufangcare-platform.git`
- 当前分支：`feature/yfth-miniapp-multi-role-shell-v1`
- 当前最新提交：以当前 Git HEAD 和 origin/main 实时值为准。
- 当前稳定 main：以当前 Git HEAD 和 origin/main 实时值为准。
- origin/main：以当前 Git HEAD 和 origin/main 实时值为准。
- 本轮开始基线：`7413627250bd057474fd2a4ea04068fae5f2ec9c`
- 当前开发阶段：多身份小程序壳层 V1 已完成 HBuilderX/uni-app 构建环境恢复、H5 开发/生产构建、浏览器运行验收和微信小程序生产编译调查；顾客端继续复用 CRMEB 页面装修，经营工作台只作为用户态壳层，不直连后台 token 页面。
- 当前工作区和推送状态：以 `git status`、当前 Git HEAD 和 origin/main 实时值为准；本轮在 `feature/yfth-miniapp-multi-role-shell-v1` 开发，不合并 `main`，不部署生产。
- 当前禁止事项和冻结模块：不得在本阶段开发核销撤销/反冲、权益恢复、评价、自动爽约、提醒消息、独立付费服务订单、跨店核销、离线码、打印码、员工排班资源、家庭成员预约、推荐奖励、配送、库存补货、产品额度、加盟合同、真实分账或生产部署；不得修改 5980 套餐支付激活、CRMEB 订单/支付/退款、后台权限核心流程或生产部署配置。
- 产品文档目录：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\项目文档`
- 当时的历史完整产品依据：`御方通和加盟小程序项目需求与产品设计文档_V1.0.docx`；当前产品依据见本文顶部最新 `Current Fact Snapshot` 指向的 Headquarters Mall 最新范围文档。

## Current Fact Snapshot - 2026-07-04 HQ Admin Productization V1

- 当前分支：`main`；功能分支 `feature/yfth-hq-admin-productization-v1` 已保留为阶段历史分支。
- 开始基线与合并前稳定 `main`：`f6ebce63d1afda54f416de41a3d2036669a0122d`。
- 本轮目标：仅产品化总部 Web 管理后台，统一品牌入口、总部工作台、总体后台一级菜单、YFTH 权限树、后台中文可见文案和正式后台静态构建产物状态；该阶段已通过最终架构复审并合并进入 `main`。
- 最终复审结论：B，P1 已关闭，无 Blocker/P1，P2/P3 后续处理。
- 已新增并验证只读后台接口：`GET home/yfth`，用于总部运营工作台真实统计和授权快捷入口；该接口不写业务数据，不触发预约、核销、支付、退款或权益状态变更。后台公共中间件会按 CRMEB 既有机制写入 `system_log` 访问日志。
- P1 权限缺口已关闭：新增 API 权限 `yfth-hq-workbench-read`，显示名称“查看总部经营工作台”，`api_url = home/yfth`，`methods = GET`，`auth_type = 2`，父级为 `admin-home` 首页工作台；普通角色必须显式授权该权限，超管继续按既有机制自动拥有。
- 服务端纵深校验已补齐：`Common::yfthWorkbench()` 在读取全局统计前调用 `SystemRoleServices::assertApiAuthForAdmin($this->adminInfo ?: [], 'home/yfth', 'GET')`，不信任前端入口、角色、门店或权限字段。
- 新增菜单迁移：`20260704110000_productize_yfth_hq_admin_menus.php`，调整总部后台一级菜单、YFTH 根菜单、YFTH 子菜单和 YFTH API 权限树中文名称；保留 `unique_auth`、菜单 ID、角色规则兼容性，不删除、不重建权限。
- 新增权限纠偏迁移：`20260704150000_add_yfth_hq_workbench_permission.php`，幂等插入/修复 `yfth-hq-workbench-read`，rollback 只移除本轮新增权限，rerun 后目标权限仍仅一条。
- 新增架构文档：`docs/YFTH_PRODUCT_SURFACE_ARCHITECTURE.md`。
- 收口整改：`GET home/yfth` 增加今日成交金额卡片；缺失可选 `yfth_` 表时降级为 0，非 YFTH 表或非缺表数据库错误继续抛出，避免掩盖真实数据库异常。
- 统计口径整改：`today_orders` 卡片改为“今日支付订单”，与“今日成交金额”共用同一个 `store_order` 查询集合：`pay_time` 在当日、`paid = 1`、`refund_status = 0`、`pid = 0`、`is_del = 0`，数量统计 count，成交金额汇总 `pay_price`。
- 验证环境：便携 PHP 7.4.33 + 隔离 MySQL 8.0.46，临时库 `yfth_hq_admin_verify`，未复制生产 `.env`，未连接生产数据库或服务器。
- 已执行验证：PHP 语法检查、`php think list`、迁移 run/rollback/rerun、`GET /adminapi/home/yfth` 未登录/超管/缺可选表降级、普通角色访问已登记 YFTH API 越权拦截、YFTH 权限菜单英文清理、`crmeb/tests/yfth_service_appointment_contract_check.php`、浏览器登录和服务预约页面加载验证。
- P1 追加验证：隔离库 `yfth_hq_p1_verify` 完整迁移 run 通过；`yfth-hq-workbench-read` 迁移前数量 0、run 后 1、rollback 后 0、rerun 后 1，重复数量 0；真实后台 token 验证未登录返回 `110003`，超管成功，无权限普通管理员返回 `100101`，有权限普通管理员成功，带门店范围但无总部工作台权限账号返回 `100101`。
- 统计测试数据覆盖：已插入今天创建未支付、昨天创建今天支付、今天支付主订单、今天支付子订单、今天支付但退款状态非 0、已删除订单、正常今日支付订单；接口返回“今日支付订单”=3，“今日成交金额”=120，仅统计符合条件的主订单。
- 合并收口：总部管理后台产品化 V1 已使用 `git merge --ff-only feature/yfth-hq-admin-productization-v1` 合并至 `main`；本轮不删除本地或远端功能分支。
- 生产状态：尚未进行生产部署，尚未执行生产数据库迁移，尚未连接生产数据库或修改服务器。
- 本轮继续冻结：CRMEB 登录鉴权、token、订单、支付、退款、商品库存主流程、5980 套餐激活主流程、服务预约/核销业务状态机、生产部署和生产数据库迁移。
- 服务预约与动态核销 V1 最新管理后台生产构建产物已刷新至 `crmeb/public/admin`；本轮核对 `template/admin/dist` 与 `crmeb/public/admin` 均为 592 个文件、39,427,546 字节且无差异，服务器后续无需执行 npm 构建即可加载相关后台页面。
- 仍保留 P2：菜单自定义名称和排序覆盖策略仍可能受未来 CRMEB 菜单变更影响，暂不阻塞总部后台产品化 V1。

## Current Fact Snapshot - 2026-07-05 Multi-role Miniapp Demo V1

- 当前开发分支：`feature/yfth-multi-role-interaction-demo-v1`。
- 开始基线：`f30426c955cce55cc552f474782c880034986514`。
- 本轮目标：新增本地可点击的“御方通和多身份小程序交互 Demo”，用于产品流程确认，不连接真实后端，不写真实数据库，不修改正式 uni-app 页面，不部署服务器。
- 新增目录：`prototype/yfth-multi-role-miniapp-v1`。
- Demo 技术边界：原生 HTML、CSS、JavaScript；不新增 npm 依赖；不依赖网络 CDN；可直接双击 `index.html`，也可用 `serve.bat` 启动本地服务。
- 已覆盖身份：顾客、加盟商、店长、店员、服务导师。
- 已覆盖交互：身份切换、经营主体切换、门店切换、五套底部导航、门店对外商店页、门店内部工作台、套餐/权益/预约/核销未来功能位置。
- 已覆盖关键流程：顾客预约、店长确认预约、店员数字码核销、加盟商多门店查看与采购演示、服务导师线索跟进。
- 规划中标记：加盟合同、奖励台账、收款设置、个人业绩、库存补货、真实采购、真实支付、真实加盟分账等仍为规划或演示流程，不得误认为已完成业务能力。
- 本轮冻结：不修改后端 API、数据库、迁移、总部 Web 后台、正式 uni-app 页面、CRMEB 登录/订单/支付/退款、5980 套餐激活、服务预约和核销状态机。
- 顾客端方向修正：顾客首页已从工作台/信息卡片式原型改为商城装修风格，参考 CRMEB 移动端首页的搜索栏、金刚区菜单、图片/内容块、楼层标题和商品列表组织方式；其他四种身份工作台本轮未大改。
- 本轮顾客端修正范围仍限 `prototype/yfth-multi-role-miniapp-v1` 和交接文档，未修改正式 `template/uni-app`、后端 API、数据库、迁移或总部后台。

## Current Fact Snapshot - 2026-07-05 Multi-role Miniapp Shell V1

- 当前开发分支：`feature/yfth-miniapp-multi-role-shell-v1`。
- 开始提交：`f56a682ca35e6c4e3e7067b3fb1bf27d0c7af264`，来自已完成的多身份静态交互 Demo 分支。
- 本轮目标：把多身份小程序方向接入正式 `template/uni-app` 基础框架，并完成运行闭环与认证边界整改；不合并 `main`，不部署生产。
- 顾客端首页继续复用 CRMEB 正式移动端首页 `pages/index/index.vue`，由页面装修数据承载搜索、轮播、菜单、图片块、商品列表和底部导航；本轮不把顾客首页改成经营工作台。
- 正式入口：`pages/user/index.vue` 的“御方通和经营工作台”只对服务端 `yfth/identities` 返回的经营身份用户展示；普通顾客不显示经营工作台入口。
- 前端上下文：`template/uni-app/libs/yfthContext.js` 通过现有 `yfth/identities`、`yfth/context`、`yfth/capability/:capability` 用户 Token 接口读取服务端身份和门店上下文；本地缓存绑定当前 CRMEB `uid`，登录、退出或用户切换会清理经营身份缓存。
- 新增页面：`template/uni-app/pages/yfth/workbench/index.vue`、`role_switch.vue`、`store_switch.vue`，并在 `pages.json` 注册。
- 复用能力：顾客商城首页、我的、商品列表、合作中心、5980 套餐和用户端预约/动态码能力继续走现有页面，不复制静态 Demo 为正式代码。
- 认证边界整改：经营工作台不再直接跳转 `/pages/admin/yfth_writeoff/index` 或 `/pages/admin/orderList/index`；核销、门店订单和门店预约管理保持“认证适配中”占位，避免普通用户 token 误连后台 API。
- 后台 API 边界：`template/uni-app/api/yfth_admin.js` 不再回退使用 `store.state.app.token`，缺少 `admin_token` 时直接 `admin_token_required` 安全失败。
- 权限边界：前端只缓存选择结果，真实身份、门店和能力仍由服务端校验；不得依赖前端传入 `store_id` 或角色字段作为最终权限依据；直接访问经营工作台且无经营身份时会清理上下文并返回顾客首页。
- 本轮未修改后端 API、数据库迁移、CRMEB 登录、订单、支付、退款、5980 套餐激活、服务预约/核销状态机或总部 Web 后台。
- 上一轮遗留构建状态：`template/uni-app/package.json` 未提供 npm 构建脚本，且当时未发现可直接复用的 HBuilderX 可执行文件；该遗留状态已在 2026-07-05 uni-app 构建恢复与运行验收中关闭，最新工具链、命令和验证结果见下一节及 `docs/YFTH_UNIAPP_BUILD_GUIDE.md`。
- 新增/更新文档：`docs/YFTH_MINIAPP_MULTI_ROLE_ARCHITECTURE.md`、`docs/YFTH_UNIAPP_BUILD_GUIDE.md`。
- 新增契约检查：`node template/uni-app/tests/yfth_multi_role_shell_contract_check.js`，覆盖页面注册、经营入口身份门控、角色白名单、缓存 uid 绑定、禁止用户态壳层直连后台核销/订单页面、CRMEB 顾客首页装修保留。
- 下一步建议：先对多身份小程序正式壳层做只读架构审核，再由项目主控决定是否进入门店/加盟商/导师等真实业务模块开发。

## Current Fact Snapshot - 2026-07-05 Uni-app Build Runtime Validation

- 当前开发分支：`feature/yfth-miniapp-multi-role-shell-v1`；本轮合法起点为 `63751f222555e9bed4e2fabeb7a918129ad95c01`，`main`/`origin/main` 仍以 `f30426c955cce55cc552f474782c880034986514` 为稳定基线；本轮不合并 `main`，不部署生产。
- 构建工具来源：从 DCloud 官方发布源准备 HBuilderX 5.14.2026070214，并放置在仓库外 `C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX`；同时从 Node.js 官方发行源准备仓库外便携 Node.js `v18.20.8`，路径为 `C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64`。
- HBuilderX CLI：`C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\cli.exe`，版本 `5.14.2026070214`；DCloud 插件安装在 HBuilderX 工具目录内，未写入仓库。
- 旧 CRMEB uni-app 项目仍使用 `node-sass`；HBuilderX 5.14 内置 Node 22 对应 ABI 127，与旧 `node-sass` 二进制不兼容。本轮用 HBuilderX 官方 `uniapp-cli` 搭配 Node 18 运行编译，未升级 Vue、uni-app、Webpack、Babel 或业务依赖。
- H5 开发/预览构建：设置 `NODE_ENV=development`、`UNI_PLATFORM=h5`、`UNI_INPUT_DIR=template/uni-app`、`UNI_OUTPUT_DIR=template/uni-app/unpackage/dist/dev/h5` 后执行 `node --max-old-space-size=5120 --no-warnings <HBuilderX>\plugins\uniapp-cli\bin\uniapp-cli.js`；本地访问地址为 `http://127.0.0.1:8080/`，构建成功并进入 watch。
- H5 生产构建：设置 `NODE_ENV=production`、`UNI_PLATFORM=h5`、`UNI_OUTPUT_DIR=template/uni-app/unpackage/dist/build/h5` 后执行同一 `uniapp-cli.js`；`index.html`、JS、CSS 和静态资源已生成，产物目录 324 个文件、9,790,085 字节；仅保留既有大资源体积 warning。
- 生产 H5 静态验收：使用 Python 静态服务打开 `http://127.0.0.1:8091/`，页面显示 CRMEB 首页安全空态“暂无商品，去看点别的吧～”，无白屏、无 JavaScript page error；因未连接本地后端，`/api/*` 和后端 `/statics/images/*` 返回 404 属于本地静态服务边界，不代表已连接生产。
- 浏览器验收结果：Edge/Chromium 实际打开顾客首页、用户中心、经营工作台直连、身份选择、门店选择；顾客首页和用户中心可渲染，经营工作台无经营身份时返回顾客首页，身份选择未登录时进入登录页，普通顾客页面未暴露后台核销或后台订单入口；未发现重定向循环。
- 本轮运行修复：忽略本地 H5 无后端时 `/api/get_script` 返回的完整 HTML，避免当作脚本执行；对 H5 devServer 返回 `index.html` 的 API fallback 转为空配置；导航 footer 无配置时关闭自定义 tabbar；CRMEB 首页无装修块时显示安全空态。
- 微信小程序编译：`cli.exe launch mp-weixin --compile true` 使用 HBuilderX 内置 Node 22 时仍触发旧 `node-sass` ABI 127 缺失；改用 HBuilderX `uniapp-cli` + Node 18 执行 `NODE_ENV=production`、`UNI_PLATFORM=mp-weixin` 的生产编译成功，输出目录为 `template/uni-app/unpackage/dist/build/mp-weixin`，1121 个文件、7,592,360 字节。未上传微信平台，未使用真实 AppID、私钥或微信开发者工具。
- 后台认证边界仍关闭：`template/uni-app/api/yfth_admin.js` 继续要求 `admin_token`，用户态 CRMEB token 不回退调用后台核销、后台订单或后台预约管理接口；经营工作台中的未适配能力仍为占位，不伪装为已完成正式业务。
- 已执行检查：`node template/uni-app/tests/yfth_multi_role_shell_contract_check.js` 通过；`git diff --check` 通过；HBuilderX H5/mp 编译覆盖 Vue SFC `<script>` 语法；HBuilderX 自带 Babel parser 对 `libs/yfthContext.js`、`utils/request.js` 和本轮修改的 Vue `<script>` 块解析通过；敏感文件和工具缓存扫描未发现待提交的 HBuilderX、Node、node_modules、`.env`、密钥、日志或临时构建缓存。
- 生产状态：未连接生产数据库，未部署服务器，未复制生产 `.env`，未使用生产 AppID、AppSecret、微信上传密钥或真实用户数据。

## 1. 项目目标

在 CRMEB 成熟商城和后台能力基础上，开发御方通和加盟 APP / 微信小程序，覆盖公共用户端、C端家庭康养会员、B端加盟商/门店工作台、A端服务导师、总部 Web 管理后台、商品商城、5980 家庭康养套餐、十个月权益、预约核销、加盟经营、推荐关系、奖励台账、内容活动、报表和审计。

当前阶段目标是完成服务预约到店履约闭环 V1 收口：服务项目、门店服务授权、排班规则、特殊日期规则、可预约时段查询、预约创建、自动确认、门店人工确认/拒绝、用户取消、门店/总部取消、同门店同项目改期、真实容量锁定/占用、5980 套餐具体服务权益锁定/释放、预约事件时间线、用户动态二维码/数字码、同店店员/店长扫码或输码核销、总部例外核销、权益最终消耗、核销记录、审计和用户/后台/门店最小页面均已落地。不得把尚未实现的撤销反冲、权益恢复、评价、自动爽约、独立付费服务订单、配送履约、奖励台账、库存补货或真实分账执行误认为已完成。

## 2. 架构概览

- 后端：`crmeb/`，ThinkPHP 6，多应用结构。
- 用户端 API：`crmeb/app/api`。
- 管理后台 API：`crmeb/app/adminapi`。
- 服务层：`crmeb/app/services`。
- DAO/模型：`crmeb/app/dao`、`crmeb/app/model`。
- 管理后台：`template/admin`，Vue2 + ElementUI。
- 移动端：`template/uni-app`，uni-app，覆盖 H5、小程序、APP。
- 初始化数据库：`crmeb/public/install/crmeb.sql`，154 张 `eb_` 表。
- 部署模板：`docker-compose/`，含 Nginx、PHP、MySQL、Redis。

## 3. 当前已完成模块

基于真实代码，当前 CRMEB 已包含：

- 登录、注册、手机号绑定、微信/小程序授权。
- 后台管理员、角色、菜单、权限和日志。
- 商品、分类、SKU、库存、购物车。
- 普通订单、支付、退款、物流、自提、核销。
- 优惠券、积分、付费会员、会员等级、营销活动。
- 分销员、推广关系、佣金、提现、分销等级。
- 门店档案、店员、门店核销订单。
- 文章、图文、页面装修、客服、消息、短信、文件上传。
- 队列、定时任务、Workerman 长连接、数据库备份、文件校验。
- 御方通和业务基础域、5980 套餐实例、十个月权益计划、真实 CRMEB 下单/支付/激活闭环、成交快照、退款生命周期、激活补偿、订单异常恢复和后台敏感操作权限校验。
- 服务项目定义、门店服务授权、周排班、特殊日期、可预约时段查询、预约创建、自动确认、门店人工确认、门店拒绝、用户取消、门店/总部取消、同门店同服务项目改期、真实时段容量锁定与占用、5980 套餐具体服务权益锁定与释放、预约事件时间线。
- 用户端预约创建、列表、详情、取消和改期最小真实页面；后台预约列表、详情、确认、拒绝和取消；真实后台 Token 门店权限；统一审计与幂等；MySQL 8.0.46 migration run、rollback、rerun 和真实预约流程验证。
- 用户端动态二维码/数字码生成与刷新、动态码状态查询、门店扫码/输码预检与核销、同店店员/店长核销权限、总部例外核销、预约到店签到时间、服务核销时间、服务完成时间、5980 服务权益最终消耗、核销记录、核销事件时间线、统一审计和幂等防重复核销。

## 4. 当前未完成模块

当前仍未完成或仅预留边界的御方通和专属模块：

- 康养中心底部导航和页面结构。
- 核销撤销/反冲和权益恢复。
- 服务评价。
- 自动爽约处理。
- 独立服务完成/重开操作；当前核销成功会在同一事务内自动完成服务。
- 独立付费服务订单。
- 微信订阅消息和短信提醒。
- 更完整的门店工作台、客户归属和经营待办。
- 加盟合同、线上签约、加盟费支付、筹备任务、开店验收和加盟商身份授予。
- 产品额度/返货额度台账。
- 服务导师线索、邀约、活动和帮扶任务。
- 只读奖励台账、规则版本、观察期、有效新客校验和冲正。
- 推荐、奖励、配送、库存补货、产品额度、加盟合同和真实分账。
- 生产部署和生产数据库迁移。

## 5. 冻结模块

后续开发应保护以下成熟模块，优先扩展而非重写：

- 登录、微信授权和 token 体系。
- 商品、SKU、库存和商品编辑器。
- 普通订单、支付回调、退款和售后。
- 后台权限、角色、菜单和操作日志。
- 门店、店员和订单核销基础能力。
- 文件上传、云存储、客服、消息和队列。
- 分销模块只可参考，不应直接承载御方通和奖励规则。

## 6. 已知问题

- 版本标识不一致：README、`.version`、移动端 manifest、后台 package 标识不同，统一认定为 v5.6 系列。
- 阻塞：仓库当前版本和历史曾包含生产 `.env`、微信支付证书/私钥、运行时 PEM、前端 AppSecret/地图 Key 类字段和压缩包；本轮已做仓库治理，但外部平台凭据仍需轮换并验证。
- 御方通和业务域已使用 `crmeb/database/migrations` 管理迁移；CRMEB 原始安装仍以 `crmeb/public/install/crmeb.sql` 为基线。
- `vendor` 和大量静态/构建相关文件仍进入仓库，后续需评估仓库体积和部署方式；本轮未移除 `vendor/`，避免改变服务器部署方式。
- 移动端配置仍有 CRMEB demo/default 配置。
- 关键产品域仍有缺口，不能把需求文档规划误写为已完成能力。
- 5980 套餐权益相关后端脚本和真实 MySQL 8.0 闭环已验证；整站本地完整启动、真实支付沙箱和生产灰度仍未验证。

## 7. 当前开发阶段

阶段：总部管理后台产品化 V1 已通过最终架构复审，原 P1 `home/yfth` 未登记权限已关闭，并已通过 fast-forward 合并进入 `main`。服务预约、容量锁定、5980 服务权益锁定与最终消耗、到店签到、动态码与服务权益核销 V1 仍作为已完成稳定能力保留。

本轮变化：

- 总部 Web 管理后台已完成品牌化入口、登录页标题与 logo fallback、首页运营工作台、中文一级菜单、YFTH 权限树中文化和正式后台静态产物刷新。
- `GET /adminapi/home/yfth` 总部工作台接口已登记 API 权限 `yfth-hq-workbench-read`，`api_url = home/yfth`，`methods = GET`，`auth_type = 2`，父级 `admin-home`。
- `Common::yfthWorkbench()` 在读取总部全局统计前显式调用 `SystemRoleServices::assertApiAuthForAdmin(..., 'home/yfth', 'GET')`，关闭未登记 API 被普通后台账号读取的 P1 缺口。
- 未登录、超管、有权限普通角色、无权限普通角色和带门店范围但无总部工作台权限账号均已验证；无权限路径返回 `100101`，未登录返回 `110003`。
- “今日支付订单”和“今日成交金额”共用同一 `store_order` 查询集合：按 `pay_time` 当日、已支付、未退款、主订单、未删除统计。
- 总部管理后台产品化 V1 已合并进入 `main`；功能分支 `feature/yfth-hq-admin-productization-v1` 保留，不删除。
- P2 继续记录：菜单名称和排序产品化迁移可能覆盖现场自定义菜单配置，后续按项目主控安排处理。
- 尚未进行生产部署，尚未执行生产数据库迁移，尚未连接生产数据库或修改服务器。

历史已完成稳定能力：

- 服务项目定义、门店服务授权、周排班规则、特殊日期规则、只读公开 API 和后台配置页面已经落地。
- Booking V1 已新增真实预约、可锁定时段实例、服务权益锁和预约事件时间线；支持用户创建、自动确认、人工确认、门店拒绝、用户取消、门店/总部取消、同门店同服务项目改期。
- 时段采用“周规则实时计算 + 特殊日期覆盖 + 预约写入时创建/复用锁定实例”，公开时段查询会叠加 `occupied_count`、`locked_count` 和 `remaining_capacity` 的真实配置内余量。
- 核销 V1 已新增动态码和核销记录模型；用户仅可在确认预约且处于到店窗口时生成二维码 token 与 6 位数字码，服务端只持久化哈希，刷新会废弃旧码。
- 同店 `store_staff`、`store_manager`、`franchisee` 可核销本店预约；总部/超管只能通过显式例外核销入口处理异常。服务端按真实后台 token 和 `AdminStoreContextServices` 校验门店范围，不依赖前端传入 `store_id`。
- 核销成功在一个事务内完成预约签到、服务权益最终消耗、权益锁 consumed、核销记录、动态码 used、预约 completed 和 `checked_in`/`benefit_written_off`/`completed` 事件写入；重复核销返回已核销结果，不重复扣减权益。
- 审计统一使用 `AuditEventServices::recordSafely()` 写入 `yfth_audit_event`，业务域为 `yfth_service_appointment`；预约状态时间线写入 `yfth_service_appointment_event`；没有使用 `yfth_sensitive_operation_log`，也没有拆分写入第二套审计表。
- 当前不支持跨日时段；尚未实现核销撤销/反冲、权益恢复、服务评价、自动爽约处理、独立付费服务订单、消息提醒、跨店核销、离线码、打印码、员工排班资源或家庭成员预约。

历史安全治理记录仍需保留，用于生产切换上下文：

- 停止跟踪生产 `.env`、微信支付证书/私钥、运行时 PEM、前端 `.env*` 和移动端压缩包。
- 新增 `crmeb/.env.example`、`template/admin/.env.example`、`template/uni-app/.env.example`。
- 将安装模板改为 `crmeb/public/install/.env.example`，安装/升级流程继续生成运行时 `crmeb/.env`。
- 清空移动端 manifest 中 AppSecret、地图 Key 类可打包敏感字段。
- 完善 `.gitignore`，覆盖环境文件、证书私钥、runtime 和备份压缩包。
- 新增 `docs/SECURITY_BASELINE.md` 与 `docs/CREDENTIAL_ROTATION_CHECKLIST.md`。
- 记录服务器影响：历史改写后服务器不得直接普通 `git pull`，后续部署需重新绑定干净历史或重新克隆并恢复本地配置。
- 新增 `docs/PRODUCTION_SECURITY_SWITCH_PREP.md`，记录生产服务器只读核验、安全备份、干净仓库克隆、配置字段兼容性、凭据轮换清单和正式切换预案。
- 生产旧目录 `/www/wwwroot/CRMEB-master` 当前保持不变；Nginx 仍指向旧目录的 `crmeb/public`。
- 服务器 SSH 克隆 GitHub 仓库失败，原因是生产服务器 GitHub SSH 身份未获授权；已通过 HTTPS 兜底克隆到 `/www/wwwroot/yufangcare-platform-clean-https` 并确认 commit 为 `9e194629da7a2bd1b4d00d4d489d9b139d43675d`。
- 安全备份目录为 `/root/yufangcare-security-backup/20260623-171035`，权限为 `root:root 700`，未放入 Web 根目录或 Git 仓库。

## 8. 下一步建议

当前服务预约与动态核销 V1、总部管理后台产品化 V1 均已正式收口并进入稳定 `main`。下一业务模块由项目主控根据完整产品流程另行决定，不再继续写“总部后台产品化架构审核”或“等待合并 main”。

生产服务器仍需保持干净克隆和凭据轮换要求：正式切换前应确认 GitHub Deploy Key 或受控 SSH 凭据可用，并确认生产 `.env`、微信支付证书、运行时 PEM 和前端环境变量均不进入 Git。

后续开发可在项目主控确认模块顺序后，再围绕核销异常反冲策略、自动爽约、消息提醒、更完整门店工作台、权益领取配送履约、推荐关系与只读奖励台账、库存补货、产品额度、加盟合同和真实分账执行等未完成能力创建独立分支。

建议先明确：

- 新增业务表和迁移方式。
- 身份模型、门店隔离和当前身份切换。
- 5980 套餐实例、权益计划和权益状态机。
- 预约、签到、权益核销、服务权益最终消耗与订单核销的边界。
- 推荐事件、规则版本、观察期、只读台账和冲正。
- 支付成功、退款成功、订单取消后的业务事件处理。

完成该任务后，建议进行一次架构审核。

## 9. 2026-06-24 业务基础域 V1 落地状态

- 当前开发分支：`feature/yfth-foundation-domain-v1`。
- 新增迁移目录 `crmeb/database/migrations`，建立 9 张 `yfth_*` 业务基础表和 `yfth-foundation-*` 后台权限点。
- 新增 `app/services/yfth`、`app/dao/yfth`、`app/model/yfth`，覆盖多身份、门店角色、经营主体、门店主体、资质、能力、收款路由、审计、幂等。
- 新增用户端基础域 API：身份列表、当前业务上下文、门店能力校验。
- 新增后台基础域管理页：`template/admin/src/pages/yfth/foundation/index.vue`。
- 修复订单核销跨店风险：店员只能核销订单原门店；重复核销返回幂等结果，不重复扣减。
- 新增文档：`YFTH_FOUNDATION_ARCHITECTURE.md`、`YFTH_FOUNDATION_DATA_MODEL.md`、`YFTH_MIGRATION_GUIDE.md`。

后续 5980 套餐、十个月权益、预约、采购、库存、奖励、支付路由执行和分账等业务，应复用本轮基础域，不得直接塞入订单备注、用户余额、分销字段或未审计 JSON。

## 10. 2026-06-24 本地工作区目录治理

- 正式本地工作区统一为：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform`。
- 旧 `testclone` 为正式仓库来源，完成目录清理后重命名为 `yufangcare-platform`。
- 旧 `yufangcare-platform` 不是顶层 Git 仓库；其中同名产品 DOCX 与正式仓库一致，补充合并唯一 Markdown：`项目文档/御方通和加盟小程序项目需求与产品设计文档_V1.0.md`。
- 旧 `yufangcare-platform-backup-20260623` 为空目录，无唯一资料。

## 11. 2026-06-24 Blocker hardening handoff

- Branch remains `feature/yfth-foundation-domain-v1`; this round fixes the architecture-audit blockers without starting package, equity, reservation, procurement, or inventory business work.
- Store context is now server-confirmed. Client `store_id` is only a candidate; store-scoped roles must resolve through `yfth_user_store_role` and an active `system_store` row. Non-store roles return `store_id = 0`.
- `franchisee` is now a store-scoped role together with `store_manager` and `store_staff`.
- Active store subject uniqueness is `store_id + subject_role`; history is preserved by disabling rows and clearing `active_key`.
- Active payment route uniqueness is `store_id + business_scene`; `resolveRoute` fails clearly when no route or historical duplicate active routes exist.
- Idempotency begin is insert-first and handles unique conflicts, payload mismatch, processing replay, expired processing recovery, succeeded replay, and failed retry visibility.
- Store order writeoff now locks the order row during confirmation. Only the first writer triggers fulfillment side effects; later writers return `is_repeat_writeoff = 1`.
- Audit and backend list output now mask `verify_code`, `credit_code`, merchant refs, certificate/id-like fields, and secret/token/password/key-like fields. Audit write failures are logged.
- Menu seed is idempotent and keeps root -> page -> API permission parent-child relationships.
- Validation completed with portable PHP 7.4.33, isolated MariaDB 10.11.18 runtime checks, PHP syntax checks, targeted frontend ESLint, and admin production build.

## 12. 2026-06-24 5980 套餐实例与十个月权益计划 V1

- 当前开发分支：`feature/yfth-package-benefits-v1`，基于 `feature/yfth-foundation-domain-v1` 的 `15f4e164b80d21a24dc721d0191ce428c0677d5b`。
- 本轮已将 `feature/yfth-foundation-domain-v1` 快进合并到 `main` 并推送，再从该基础创建套餐权益开发分支；本轮结束后不得把该功能分支合并回 `main`。
- 新增 11 张 `yfth_*` 套餐权益表，覆盖套餐模板、规则版本、商品/SKU 绑定、协议快照、购买绑定、套餐实例、权益模板、月度规则、权益计划、月度周期和权益项。
- 新增套餐权益服务层，围绕规则快照、购买前校验、支付后幂等激活、十个月计划生成、月度权益开启/过期、退款同步和 `member_5980` 身份重算实现闭环。
- 支付成功和退款相关逻辑通过事件监听器接入，未改写 CRMEB 支付回调、订单创建、退款主流程、购物车、商品 SKU、用户 token 或文件上传等冻结模块。
- 套餐购买必须通过手机号、协议接受、商品/SKU 绑定、金额快照、门店主体、门店能力、收款路由和服务门店权限校验，不把权益写入订单备注、用户余额、积分、佣金或分销字段。
- 后台新增 `yfth/package_benefit/*` API、菜单权限和 Vue 管理页，支持模板、规则、绑定、月度权益规则、购买记录、实例和计划查看、到期周期开启。
- 移动端新增套餐详情、门店选择、协议确认、支付确认/结果、我的套餐、套餐实例、时间轴和当月权益页面，并在 `pages.json` 注册 `pages/yfth` 分包。
- 新增文档：`YFTH_PACKAGE_BENEFIT_ARCHITECTURE.md`、`YFTH_PACKAGE_BENEFIT_DATA_MODEL.md`、`YFTH_PACKAGE_BENEFIT_STATE_MACHINE.md`。
- 新增验证脚本：`crmeb/tests/yfth_package_benefit_contract_check.php` 和 `crmeb/tests/yfth_package_benefit_runtime_check.php`。

仍未完成的后续域：服务项目、预约时段、动态权益核销码、权益履约消费明细、门店工作台、推荐关系、只读奖励台账、库存补货、产品额度、加盟合同和支付路由真实分账执行。

## 13. 2026-06-24 套餐支付激活一致性整改

- 当前开发分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`b811fc585c774ed7dd42a2c2e1252833b35685c7`。
- 本轮新增纠偏 migration：`20260624170000_harden_yfth_package_purchase_snapshots.php` 和 `20260624170010_seed_yfth_package_recovery_menus.php`。
- 新增 `yfth_package_purchase_intent`、`yfth_package_purchase_snapshot`、`yfth_package_purchase_benefit_snapshot`，成交时把套餐规则、协议、门店主体、支付路由、商品/SKU、月度权益逐行固化为关系型快照。
- `yfth_package_purchase` 增加可空唯一键 `order_unique_key`、`order_sn_unique_key`，用 MySQL 唯一索引保证一个 CRMEB 订单只能绑定一条套餐购买记录；服务层捕获唯一冲突后回查并返回已有购买记录。
- uni-app 套餐购买页已移除手工订单号、商品 ID、SKU unique 输入，改为 `createIntent -> createOrderFromIntent -> CRMEB payment`，支付结果页轮询真实购买激活状态。
- 支付激活只读取已绑定订单、购买记录和成交快照，不再读取实时可编辑模板/权益配置；激活失败会写入重试字段，并可通过幂等记录原子重新抢占。
- 新增自动/人工补偿：后台 `activation/recover`、`purchase/:id/activation_retry`，以及命令 `php think yfth:package recover-activation --limit 50`。
- 新增集中生命周期服务，退款中、全额退款、部分履约后退款关闭、人工关闭/冻结均通过统一状态机联动 purchase、instance、plan、period、item 和 `member_5980` 身份。
- `openDuePeriods` 改为批量上限、逐条锁定、计划/实例 active 二次校验；冻结、退款中、已退款、关闭状态不再开放未来月份或延迟权益项。
- 退款事件映射改为优先解析 `store_order_id/store_order_sn`，找不到时通过真实退款单回查原订单；映射失败写技术日志和审计待补偿记录。
- 新增真实应用验证脚本 `crmeb/tests/yfth_package_benefit_real_flow_check.php`，用于 MySQL 5.7/8.0 测试库上的真实迁移、表/索引、Service、Listener 和可选下单激活闭环验证；旧 runtime 脚本仍仅可作为轻量回归，不可替代最终验收。

## 14. 2026-06-25 5980 套餐权益真实 MySQL 隔离验收

- 当前开发分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`c200ef37f6cbf168a79aa3c493995373ed09521b`。
- 本轮使用 MySQL Community Server 8.0.46 官方 ZIP 在本地隔离端口 `127.0.0.1:33306` 验收，不使用 MariaDB，不连接生产库，不读取生产 `.env`。
- `crmeb/public/install/crmeb.sql` 已导入真实 CRMEB 基线库；导入时仅会话级放宽历史 SQL 兼容项，全局 MySQL 严格模式保持不变。
- 御方通和 6 个 migration 完成真实 MySQL 上的 run、rollback 到 0、再次 run：迁移后 178 张表、23 张 `eb_yfth_*` 表、37 个 `yfth-%` 后台权限点；回滚后 YFTH 表和权限点均清零。
- 修复真实 MySQL 暴露的阻塞问题：菜单 `sort` 越界、Phinx 索引/回滚 API 兼容、YFTH 整型时间戳被 ThinkORM 自动时间戳误解析、套餐购买旧路径未定义变量、并发唯一冲突恢复、`member_5980` active 身份重复写入、规则引用计数误判。
- 真实闭环脚本 `crmeb/tests/yfth_package_benefit_real_flow_check.php` 已扩展为隔离执行模式，覆盖真实下单激活、重复支付幂等、失败激活重试、补偿恢复、10 进程并发绑定、冻结/退款生命周期、规则不可变和复制新版本。
- 最终真实闭环验证输出：`[OK] YFTH package benefit real application checks verified on MySQL 8.0.46.`
- 新增验收文档：`docs/YFTH_PACKAGE_BENEFIT_RUNTIME_VALIDATION.md`。
- 前端 `template/admin` 生产构建通过；强制绕过 ignore 的 ESLint 仅暴露既有 CRLF 行尾 Prettier 问题，本轮未批量格式化前端文件。
- 本轮结束后不得合并到 `main`，也不得部署到生产；需先完成后续架构审核，再决定是否进入发布准备。

## 15. 2026-06-25 套餐 intent 并发建单与人工激活恢复整改

- 当前开发分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`527aacbd10b8c3a5346d713f5a41d37951b0811f`。
- intent 下单增加 `creating_started_at`、`creating_request_id`、`bound_order_id/sn`、`orphan_order_id/sn`、`last_error_code/message`、`retry_count` 等字段和索引；同一 intent 通过数据库行锁抢占串行化 CRMEB 建单。
- `createOrderFromIntent()` 只允许抢占成功者调用 CRMEB 订单创建；重复请求返回已绑定订单或处理中状态，不再产生多个待支付 CRMEB 订单。
- 订单创建成功但绑定失败时，记录孤儿订单并调用 CRMEB 取消订单能力补偿，不做物理删除；新增 `scan-orphan-orders --close` 命令用于扫描和人工收口。
- 自动激活失败达到幂等最大次数后，自动补偿不再反复抢占；人工重试必须提供操作人和原因，走独立 `manual_activate` 幂等键并记录人工重试次数、时间、操作人和结果。
- 后台套餐购买列表新增自动重试次数/上限、是否可人工重试、最近人工重试操作人与结果展示；人工重试入口增加原因输入和二次确认。
- 真实 MySQL 8.0.46 隔离验证已覆盖：新 migration run/rollback/run、同一 intent 10 进程并发只生成 1 个 CRMEB 订单、孤儿可支付订单为 0、自动重试上限跳过、人工重试覆盖上限并激活、人工并发只生成 1 个实例。
- 本轮仍不代表服务预约、签到、动态权益核销码、配送履约、奖励台账、库存补货、产品额度、加盟合同或真实支付分账执行已经完成。

## 16. 2026-06-26 后台权限强制校验与未记录孤儿订单恢复整改

- 当前分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`0b309ddab919850836f22e0ff2671f86b4a4503d`。
- 后台权限 P1 已关闭：`SystemRoleServices::verifyAuth()` 对已登记 API 不再默认放行，未授权普通角色抛出 `AuthException(100101)`；未登记 API、CRUD 和超管保持兼容放行。
- 敏感入口增加纵深校验：人工激活重试、激活恢复、orphan 扫描均在 Controller 侧通过当前管理员身份再次调用 `assertApiAuthForAdmin()`，空后台身份不会被误判为超管。
- 新增 `yfth_package_order_attempt`，在 CRMEB 建单前用服务端生成的 `orderKey` 持久化 attempt，并通过 `store_order.unique` 反查 intent/attempt；不使用订单备注、展示备注、前端标记或 UID/SKU/时间猜测来源。
- `creating` 超时恢复覆盖无订单、未支付 orphan、已支付 orphan、已关闭订单和旧请求延迟返回。未支付 orphan 只在 CRMEB 原生取消成功后允许重试；已支付 orphan 进入 `orphan_paid_pending` 并走受控恢复，不创建第二张订单。
- `scan-orphan-orders` 默认 dry-run；显式 `--close-unpaid` 才关闭未支付 orphan，显式 `--recover-paid` 才恢复已支付 orphan。后台新增 orphan 扫描入口，仍受独立权限控制。
- 支付 listener 发现套餐来源订单缺少 purchase 时写 `Log::error` 和 YFTH 审计/恢复记录，日志只包含脱敏订单号、order id、intent/attempt、request id 和安全错误码，不影响 CRMEB 支付成功主流程。
- 真实 MySQL 8.0.46 隔离验证通过：临时端口 `127.0.0.1:33326`，临时库 `yufangcare_validation_20260626_orphanfix`；migration 完成 run/rollback/run，回滚后 YFTH 表/菜单/迁移记录均为 0，第二次 run 后 179 张表、24 张 `eb_yfth_*` 表、8 条 YFTH migration、38 个 `yfth-%` 权限点。
- `crmeb/tests/yfth_package_benefit_real_flow_check.php` 已覆盖真实权限中间件、未登录/超管/授权/未授权角色、真实 CRMEB 下单、未支付 orphan 关闭、已支付 orphan 恢复、无订单超时重试、旧请求延迟保护、10 进程 intent 并发和并发人工激活。
- 本轮验证后已删除临时 MySQL 库、用户、data dir 和验证副本；未提交临时 `.env`、测试密码或验证数据。
## 17. 2026-06-26 Service Appointment Domain V1 / 服务项目与门店预约时段基础域 V1

- 当前开发分支：`feature/yfth-service-appointment-writeoff-v1`；基于 `main` 的 `7413627250bd057474fd2a4ea04068fae5f2ec9c` 开始。
- 本历史阶段新增服务项目、门店服务授权、周排班规则、特殊日期规则和只读可预约时段查询；当时没有实现预约提交、确认/取消/改期、签到、动态码、扫码核销、独立付费服务订单、消息通知或推荐奖励。该段为 2026-06-26 历史记录，不代表当前 Booking V1 状态。
- 新增迁移：`20260626130000_create_yfth_service_appointment_tables.php` 和 `20260626130010_seed_yfth_service_appointment_menus.php`。
- 新增表：`yfth_service_project`、`yfth_store_service`、`yfth_store_service_schedule_rule`、`yfth_store_service_special_day`。服务项目保持独立业务对象，不复用普通商品；门店服务授权通过 `store_id + service_project_id` 的 active key 防重复；周规则和特殊日期均保留历史停用记录。
- 历史时段方案：基础域 V1 采用“周规则实时计算 + 特殊日期覆盖”，当时不预生成 slot 表，也不写虚假的已预约人数。Booking V1 已在预约写入时创建/复用 `yfth_service_appointment_slot` 并叠加真实锁定/占用。
- 权限边界：后台 API 继续走 CRMEB 菜单/API 权限；服务层再按服务端 `adminInfo` 的门店范围校验。总部可维护服务项目和门店授权；店长可在本店范围维护排班和特殊日期；店员不能配置服务项目、门店服务、排班或容量。
- 门店可用性：只读查询会校验门店存在且启用、门店拥有 `reservation_service` 能力、服务项目 active、门店服务授权 active 且 appointment enabled。当前未建设完整资质中心，继续复用既有 `StoreCapabilityServices` 作为扩展点，不把资质硬编码为永远通过。
- 后台入口：`template/admin/src/pages/yfth/serviceAppointment/index.vue`，支持服务项目、门店服务授权、排班规则、特殊日期和时段预览。
- 小程序端历史状态：基础域 V1 仅新增 `template/uni-app/api/yfth.js` 的只读 API 封装；Booking V1 已新增预约创建、列表、详情、取消和改期最小真实页面。
- 新增只读公开接口：`yfth/service/project`、`yfth/service/project/:id`、`yfth/service/project/:id/stores`、`yfth/service/project/:id/dates`、`yfth/service/project/:id/slots`。
- 审计：服务项目、门店授权、排班规则和特殊日期的新增、更新、停用均写入 `yfth_audit_event`，业务域为 `yfth_service_appointment`。
- 历史下一轮建议：基础域 V1 后续“预约创建、取消、改期和权益锁定”应复用查询和绑定服务；该能力已由 Booking V1 完成。当前下一轮应复用 `ServiceAppointmentBookingServices`、`ServiceAppointmentQueryServices`、`StoreServiceAppointmentServices`、`yfth_service_appointment`、`yfth_service_appointment_slot` 和 `yfth_service_benefit_lock` 进入签到、动态码、扫码核销和最终权益消耗。
- 不得重复开发的稳定能力：5980 套餐购买、CRMEB 订单/支付/退款、成交快照、权益计划、激活补偿、订单异常恢复、后台权限强校验、门店能力/资质扩展点和 YFTH 审计能力。
- 已知限制：当前不支持跨日服务时段；特殊关闭按整日关闭处理；Booking V1 已有真实预约、时段占用和权益锁，但尚未实现签到、核销、服务完成、爽约或最终权益消耗；服务项目的权益模板范围先以服务类 benefit template id 列表表达，后续如范围复杂化可拆独立关系表。

## Current Fact Snapshot - 2026-06-27 Service Appointment P1 Hardening

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Current latest commit: this P1 hardening commit; use Git HEAD on this branch after commit.
- Current stage: service project, store service authorization, weekly schedule rules, special-day rules, and read-only slot query foundation are complete; next round remains appointment creation, cancellation, reschedule, and benefit locking.
- Backend service appointment writes now resolve headquarters/store scope from real CRMEB admin tokens through `AdminAuthTokenMiddleware`, `AdminStoreContextServices`, and `yfth_admin_store_scope`.
- Client-injected `store_id`, `store_ids`, or role-like fields are not trusted for store write permission.
- Store managers/franchisees can write only scoped active-store schedules/special days; store staff and no-scope admins cannot configure service projects, store authorization, schedules, or capacity.
- Strict service date parsing rejects invalid `YYYYMMDD` and `YYYY-MM-DD` calendar dates, including non-leap `20260229`, and accepts leap day `20280229`.
- Existing `yfth_store_service` identity is immutable after creation: `store_id` and `service_project_id` cannot be changed on update.
- Public service project detail uses a whitelist and does not expose backend maintenance fields.
- Frozen modules: no appointment creation, benefit lock/release, check-in, dynamic code, writeoff, paid service order, notification, reward, delivery, settlement, production deployment, or production database operation was started in this round.
- Push status: this P1 round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Service Appointment Booking V1

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Start commit for this round: `7a3a8ef64bb193e4a52fc623e4e877b1c247c595`.
- Current latest commit: this booking V1 commit; use Git HEAD on this branch after commit.
- Current stage: service project, store service authorization, weekly schedule/special-day slot foundation, appointment creation, manual confirm, reject, cancel, reschedule, true slot capacity locking, and 5980 service-benefit locking are implemented on the feature branch.
- Completed Booking V1 capabilities include auto confirm, store/headquarters cancel, appointment event timeline, real backend token store permission, unified audit/idempotency, user appointment create/list/detail/cancel/reschedule pages, admin appointment list/detail/confirm/reject/cancel page, and MySQL 8.0.46 migration run/rollback/rerun plus real booking flow validation.
- New tables in this round: `yfth_service_appointment`, `yfth_service_appointment_slot`, `yfth_service_benefit_lock`, and `yfth_service_appointment_event`.
- Slot strategy remains `weekly rule realtime calculation + special-day overlay`; booking writes create/reuse lockable slot instances only for selected slots.
- User APIs/pages now cover available service benefits, create appointment, my appointment list, detail, cancel, reschedule-slot query, and reschedule submission.
- Admin APIs/page now cover appointment list/detail, pending appointment confirmation, rejection, and cancellation.
- Audit remains unified through `AuditEventServices::recordSafely()` into `yfth_audit_event`, business domain `yfth_service_appointment`; appointment history also writes `yfth_service_appointment_event`.
- Idempotency uses existing `yfth_idempotency_record` via `IdempotencyRecordServices`.
- Frozen modules remain: check-in, dynamic QR/code, scan writeoff, final service consumption, no-show/completion operations, paid service order, messages, rewards, delivery, settlement, production deployment, and production database operations.
- Next round should reuse `ServiceAppointmentBookingServices`, `ServiceAppointmentQueryServices`, `StoreServiceAppointmentServices`, `yfth_service_appointment`, `yfth_service_appointment_slot`, and `yfth_service_benefit_lock` to implement check-in, dynamic code, writeoff, and final benefit consumption.
- Push status: this booking V1 round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Service Check-in And Writeoff V1

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Start commit for this round: `1db8fbc2fabd609e9fce8b4258b1639c9bbe1eec`.
- Current latest commit: this check-in/writeoff V1 commit; use Git HEAD on this branch after commit.
- Completed capabilities: user dynamic QR token and 6-digit digital code generation/refresh/status, hash-only code persistence, old-code invalidation, code expiry, store precheck, store QR writeoff, store digital writeoff, headquarters exception writeoff, same-store staff/manager/franchisee writeoff, appointment check-in/writeoff/completion timestamps, final service-benefit consumption, writeoff record list/detail, user writeoff result display, mobile store scan/input page, and admin writeoff status/record visibility.
- New persistence: `yfth_service_dynamic_code`, `yfth_service_writeoff_record`, appointment writeoff columns, and benefit-lock consumed/writeoff columns.
- Service classes added: `ServiceAppointmentWriteoffServices` and `ServiceBenefitConsumptionServices`; Booking V1 continues to own creation, confirmation, cancellation, reschedule, slot capacity, and service-benefit lock creation/release.
- Dynamic code rules: only appointment owners can generate codes; appointments must be `confirmed`, uncompleted, within the default check-in window of 30 minutes before start to 120 minutes after end, and have an active service-benefit lock. QR tokens and numeric codes are returned only at generation time; stored values are SHA-256 hashes.
- Digital-code hardening: numeric-code precheck is read-only; numeric-code lookup first resolves real backend admin store scope and then searches only that trusted scope; random missing codes, other-store real codes, expired codes, invalidated codes, and rate-limited attempts share the same safe error semantics; failed numeric attempts are limited by administrator/store-scope/IP short-window counters instead of globally consuming a real code row.
- Active same-store numeric code uniqueness is protected by `yfth_service_dynamic_code.digital_active_key` and `uniq_yfth_svc_code_store_digital_active`; code generation retries finite same-store numeric collisions and clears active keys on refresh, expiry, invalidation, and successful writeoff.
- Headquarters exception writeoff now requires a service-side non-empty reason and persists it to writeoff record, appointment event, and unified YFTH audit paths.
- Writeoff transaction: locks appointment, dynamic code when present, benefit lock, benefit item and parent rows; then writes one successful writeoff record, marks the benefit item `used`, marks the benefit lock `consumed`, marks the appointment `completed`, records `checked_in`, `benefit_written_off`, and `completed` events, and records unified YFTH audit entries.
- Repeat writeoff behavior: a completed appointment returns `already_written_off` or replayed idempotent result and does not create a second writeoff record or consume the benefit item again.
- P2 hardening in this round: user appointment list/detail responses now use a whitelist and no longer expose raw `events`, raw `benefit_lock`, request ids, idempotency keys, snapshots, or backend operator fields; user reschedule now locks old/new slots by stable slot id order with finite deadlock retry.
- Audit remains unified through `AuditEventServices::recordSafely()` into `yfth_audit_event`, business domain `yfth_service_appointment`; appointment history also writes `yfth_service_appointment_event`.
- Frozen modules remain: writeoff reversal/refund recovery, service review, automatic no-show, notification messages, paid service order, cross-store/offline/printed codes, staff resource scheduling, family-member booking, rewards, delivery, inventory, settlement, production deployment, and production database operations.
- Current service appointment and dynamic writeoff V1 has passed final architecture review and is allowed to merge into `main`. The next business module must be selected separately by the project owner from the full product flow.
- Push status: this check-in/writeoff V1 round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Digital Writeoff Code Hardening

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Start commit for this round: `25c6ac45aa3a41c197964c006bfa3ef60a888e07`.
- Current latest commit: this digital-code hardening commit; use Git HEAD on this branch after commit.
- Fixed security issues: digital-code precheck no longer mutates dynamic-code status or attempt counters; digital precheck/writeoff resolves trusted backend store scope before querying any numeric code; random missing codes and other-store real codes return the same safe error; cross-store input does not consume the real user's code attempts or state.
- Brute-force protection now uses a short-lived request counter keyed by administrator id, trusted store scope, request IP, and digital writeoff scene. The first through configured maximum failed attempts are allowed to execute; the next request is temporarily limited with the same safe error semantics. Successful digital writeoff resets the counter.
- Added persistence hardening: `20260703130000_harden_yfth_service_dynamic_codes.php` adds `digital_active_key`, `uniq_yfth_svc_code_store_digital_active`, `idx_yfth_svc_code_store_digital`, and writeoff-record `reason`.
- Same-store active numeric collisions are blocked by the database and generation retries with a finite limit. The same 6-digit code may exist in different stores, but lookup is always restricted to the real operator scope.
- Headquarters exception writeoff requires a non-empty service-side reason; missing, blank, or too-short reasons are rejected before any writeoff transaction. Valid reasons are written to the writeoff record, appointment events, and YFTH audit.
- Added negative real-flow coverage for read-only precheck, cross-store true code, random-code equivalence, rate-limit boundary, same-store active-code unique constraint, different-store same-code allowance, and headquarters exception reason validation.
- Still not implemented: writeoff reversal/refund recovery, automatic no-show, notification messages, paid service order, offline/printed codes, cross-store writeoff, production deployment, or production database migration.
- Digital-code security hardening targeted review result: B, conditionally passed. The original digital-code P1 is closed; there are no current Blocker/P1 issues for service appointment and dynamic writeoff V1.
- Service appointment and dynamic writeoff V1 is allowed to merge into `main`; the project may enter the next business module after merge, but the module must be selected separately by the project owner.
- Push status: this hardening round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Final Service Appointment And Writeoff V1 Closure

- Current branch after merge: `main`.
- Document closure commit and current stable main: `f9a0d963ac4c92120111983c9b489433f1ab0dca`.
- Current origin/main: `f9a0d963ac4c92120111983c9b489433f1ab0dca`.
- Feature branch retained: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main before merge: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Final review conclusion: digital-code security hardening targeted review result is B, conditionally passed; the original digital-code P1 is closed; there are no current Blocker/P1 issues.
- Merge result: service appointment and dynamic writeoff V1 was merged into `main` with `git merge --ff-only`; `main` and the feature branch pointed to the same commit at merge time, and `origin/main` was pushed successfully to `f9a0d963ac4c92120111983c9b489433f1ab0dca`.
- Completed stable capabilities: service project definition, store service authorization, weekly schedule and special days, available dates and slots, appointment creation, auto/manual confirmation, rejection, cancellation, same-store same-project reschedule, true capacity locking/occupation, 5980 service-benefit lock/release/final consumption, check-in, dynamic QR token, 6-digit digital writeoff code, same-store staff/manager/franchisee QR or digital writeoff, headquarters exception writeoff, appointment completion, writeoff records, events, unified audit, idempotency, and minimum real user/store/admin pages.
- Validation basis: MySQL 8.0.46 migration run, rollback, rerun, and real-flow validation were completed in the feature branch before this final documentation closure.
- Digital-code hardening facts: precheck is read-only; backend admin token and trusted store scope are resolved before numeric-code lookup; other-store real codes, random wrong codes, expired codes, and invalidated codes share safe error semantics; failure throttling is keyed by administrator, trusted store scope, IP, and business scene; failed attempts 1 through 5 execute, and the 6th request is temporarily limited; same-store active numeric codes are protected by `digital_active_key`; generation retries finite collisions; headquarters exception writeoff reason is required on the service side.
- Non-blocking P2: expired digital codes may keep occupying `digital_active_key` until refresh or cleanup is triggered.
- Non-blocking P2: when Cache/Redis has an exception, the digital-code entry fails closed, but the degraded response and operator experience can still be improved.
- Non-blocking P3: no real wait-300-seconds TTL recovery test was executed; a future test can use injectable time or cache fake support.
- Still not implemented: writeoff reversal/reversal accounting, benefit recovery, automatic no-show, service review, WeChat subscription messages, SMS reminders, independent paid service orders, fuller store workstation, delivery fulfillment, recommendation/reward ledger, inventory replenishment, product quota, franchise contracts, real settlement, production deployment, and production database migration.
- Next state: current service appointment and dynamic writeoff V1 has passed final architecture review and is already merged and pushed to `main`. The next business module must be determined separately by the project owner from the complete product flow.
- Production status: no production deployment was performed, and no production database was connected during the merge closure.
- Admin asset status: the latest service appointment and dynamic writeoff V1 admin production build has been refreshed into `crmeb/public/admin`, so later server updates can load the related admin pages without running npm build on the server.
