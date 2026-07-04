<template>
  <div class="yfth-workbench" v-loading="loading">
    <section class="workbench-head">
      <div>
        <h1>{{ platform.name }}</h1>
        <p>{{ platform.description }}</p>
      </div>
      <el-tag type="success" effect="plain">总部运营</el-tag>
    </section>

    <el-row :gutter="14" class="stat-grid">
      <el-col v-bind="statGrid" v-for="item in cards" :key="item.key">
        <el-card shadow="never" class="stat-card">
          <div class="stat-title">{{ item.title }}</div>
          <div class="stat-value">{{ item.value }}</div>
          <div class="stat-desc">{{ item.desc }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="14">
      <el-col :xl="16" :lg="16" :md="24" :sm="24" :xs="24">
        <section class="panel">
          <div class="panel-title">
            <h2>常用入口</h2>
            <span>仅显示当前账号已授权的真实功能</span>
          </div>
          <el-empty v-if="visibleQuickLinks.length === 0" description="暂无可用入口" :image-size="96" />
          <div v-else class="quick-grid">
            <button v-for="item in visibleQuickLinks" :key="item.path" type="button" @click="go(item.path)">
              <span>{{ item.title }}</span>
              <small>{{ item.desc }}</small>
            </button>
          </div>
        </section>
      </el-col>

      <el-col :xl="8" :lg="8" :md="24" :sm="24" :xs="24">
        <section class="panel">
          <div class="panel-title">
            <h2>运营待办</h2>
            <span>来自预约和核销真实状态</span>
          </div>
          <el-empty v-if="visibleTodos.length === 0" description="暂无待处理事项" :image-size="96" />
          <div v-else class="todo-list">
            <button v-for="item in visibleTodos" :key="item.title" type="button" @click="go(item.path)">
              <span>{{ item.title }}</span>
              <strong>{{ item.count }}</strong>
            </button>
          </div>
        </section>
      </el-col>
    </el-row>
  </div>
</template>

<script>
import { yfthWorkbenchApi } from '@/api/index';
import { mapState } from 'vuex';

export default {
  name: 'index',
  data() {
    return {
      loading: false,
      platform: {
        name: '御方通和总部运营管理平台',
        description: '商城、门店、套餐权益、服务预约与核销的总部运营入口',
      },
      cards: [],
      todos: [],
      quickLinks: [],
      statGrid: {
        xl: 6,
        lg: 6,
        md: 12,
        sm: 12,
        xs: 24,
      },
    };
  },
  computed: {
    ...mapState('userInfo', ['userInfo', 'uniqueAuth']),
    isSuperAdmin() {
      return Number((this.userInfo && this.userInfo.level) || -1) === 0;
    },
    visibleQuickLinks() {
      return this.quickLinks.filter((item) => this.hasAuth(item.auth));
    },
    visibleTodos() {
      return this.todos.filter((item) => this.hasAuth(item.auth));
    },
  },
  mounted() {
    this.getWorkbench();
  },
  methods: {
    getWorkbench() {
      this.loading = true;
      yfthWorkbenchApi()
        .then((res) => {
          const data = res.data || {};
          this.platform = data.platform || this.platform;
          this.cards = data.cards || [];
          this.todos = data.todos || [];
          this.quickLinks = data.quick_links || [];
        })
        .catch((res) => {
          this.$message.error((res && res.msg) || '工作台数据加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    hasAuth(auth) {
      if (this.isSuperAdmin || !auth || !auth.length) return true;
      const access = this.uniqueAuth || [];
      return auth.some((item) => access.includes(item));
    },
    go(path) {
      if (!path) return;
      this.$router.push({ path });
    },
  },
};
</script>

<style lang="scss" scoped>
.yfth-workbench {
  min-height: calc(100vh - 120px);
  color: #1f2933;
}

.workbench-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 18px 20px;
  margin-bottom: 14px;
  background: #ffffff;
  border: 1px solid #e8ecf1;
  border-radius: 6px;

  h1 {
    margin: 0;
    font-size: 22px;
    font-weight: 600;
    line-height: 30px;
  }

  p {
    margin: 6px 0 0;
    color: #667085;
    font-size: 14px;
    line-height: 22px;
  }
}

.stat-grid {
  margin-bottom: 2px;
}

.stat-card {
  margin-bottom: 14px;
  border-radius: 6px;

  .stat-title {
    color: #667085;
    font-size: 13px;
    line-height: 20px;
  }

  .stat-value {
    margin-top: 8px;
    font-size: 28px;
    font-weight: 600;
    line-height: 36px;
  }

  .stat-desc {
    margin-top: 6px;
    color: #8a94a6;
    font-size: 12px;
    line-height: 18px;
  }
}

.panel {
  min-height: 274px;
  padding: 18px 20px;
  margin-bottom: 14px;
  background: #ffffff;
  border: 1px solid #e8ecf1;
  border-radius: 6px;
}

.panel-title {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 14px;

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    line-height: 24px;
  }

  span {
    color: #8a94a6;
    font-size: 12px;
  }
}

.quick-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 10px;

  button {
    min-height: 76px;
    padding: 12px;
    text-align: left;
    background: #f7f9fc;
    border: 1px solid #e5e9f0;
    border-radius: 6px;
    cursor: pointer;

    span {
      display: block;
      color: #1f2933;
      font-size: 14px;
      font-weight: 600;
      line-height: 22px;
    }

    small {
      display: block;
      margin-top: 6px;
      color: #667085;
      font-size: 12px;
      line-height: 18px;
    }

    &:hover {
      border-color: #2f80ed;
      background: #eef6ff;
    }
  }
}

.todo-list {
  display: grid;
  gap: 10px;

  button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 48px;
    padding: 0 12px;
    background: #fff7ed;
    border: 1px solid #ffd7a8;
    border-radius: 6px;
    cursor: pointer;

    span {
      color: #7c2d12;
      font-size: 14px;
    }

    strong {
      color: #c2410c;
      font-size: 18px;
    }
  }
}
</style>
