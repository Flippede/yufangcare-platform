<template>
	<view>
		<view class="yfth-business-footer-fixed">
			<view class="yfth-business-footer">
				<view
					v-for="(item, index) in items"
					:key="index"
					class="yfth-business-footer-item"
					:class="{ active: isActive(item) }"
					@click="$emit('select', item)"
				>
					{{ item.title }}
				</view>
			</view>
		</view>
		<view v-if="reserveSpace" class="yfth-business-footer-space"></view>
		<view v-if="reserveSpace" class="safe-area-inset-bottom"></view>
	</view>
</template>

<script>
export default {
	name: 'YfthBusinessFooter',
	props: {
		items: {
			type: Array,
			default: () => []
		},
		activeAction: {
			type: String,
			default: ''
		},
		activePane: {
			type: String,
			default: ''
		},
		reserveSpace: {
			type: Boolean,
			default: false
		}
	},
	methods: {
		isActive(item) {
			if (!item) return false;
			return (item.action && item.action === this.activeAction)
				|| (item.pane && item.pane === this.activePane);
		}
	}
};
</script>

<style scoped>
.yfth-business-footer-fixed {
	position: fixed;
	z-index: 999;
	right: 0;
	bottom: 0;
	left: 0;
	width: 100%;
	max-width: 750px;
	margin: 0 auto;
	padding-bottom: env(safe-area-inset-bottom);
	box-sizing: border-box;
	border-top: 1rpx solid #eadfce;
	background: #fffaf4;
}

.yfth-business-footer {
	display: flex;
	height: 106rpx;
}

.yfth-business-footer-item {
	display: flex;
	min-width: 0;
	flex: 1;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	color: #786b73;
	font-size: 23rpx;
	line-height: 1;
	text-align: center;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.yfth-business-footer-item.active {
	color: #6f4c2f;
	font-weight: 700;
}

.yfth-business-footer-space {
	height: 106rpx;
}

.safe-area-inset-bottom {
	height: env(safe-area-inset-bottom);
}

/* #ifdef H5 */
@media screen and (min-width: 768px) {
	.yfth-business-footer-fixed {
		right: auto;
		left: 50%;
		width: 540px;
		max-width: 100%;
		margin: 0;
		transform: translateX(-50%);
	}
}
/* #endif */
</style>
