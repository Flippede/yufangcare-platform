import LayoutMain from '@/layout';
import setting from '@/setting';
let routePre = setting.routePre;

const pre = 'yfth_';

export default {
  path: routePre + '/yfth',
  name: 'yfth',
  header: 'yfth',
  redirect: {
    name: `${pre}foundation`,
  },
  meta: {
    auth: true,
  },
  component: LayoutMain,
  children: [
    {
      path: 'foundation',
      name: `${pre}foundation`,
      meta: {
        auth: ['yfth-foundation-index'],
        title: '业务基础域',
      },
      component: () => import('@/pages/yfth/foundation/index'),
    },
    {
      path: 'package-benefit',
      name: `${pre}package_benefit`,
      meta: {
        auth: ['yfth-package-benefit-index'],
        title: '套餐权益',
      },
      component: () => import('@/pages/yfth/packageBenefit/index'),
    },
    {
      path: 'service-appointment',
      name: `${pre}service_appointment`,
      meta: {
        auth: ['yfth-service-appointment-index'],
        title: '服务预约与核销',
      },
      component: () => import('@/pages/yfth/serviceAppointment/index'),
    },
    {
      path: 'franchise-application',
      name: `${pre}franchise_application`,
      meta: {
        auth: ['yfth-franchise-application-index'],
        title: '加盟管理',
      },
      component: () => import('@/pages/yfth/franchiseApplication/index'),
    },
    {
      path: 'supply-chain',
      name: `${pre}supply_chain`,
      meta: {
        auth: ['yfth-supply-chain-index'],
        title: 'Supply Chain',
      },
      component: () => import('@/pages/yfth/supplyChain/index'),
    },
    {
      path: 'franchise-opening',
      name: `${pre}franchise_opening`,
      meta: {
        auth: ['yfth-franchise-opening-index'],
        title: 'Franchise Opening',
      },
      component: () => import('@/pages/yfth/franchiseOpening/index'),
    },
    {
      path: 'referral-reward',
      name: `${pre}referral_reward`,
      meta: {
        auth: ['yfth-referral-reward-index'],
        title: '推荐奖励台账',
      },
      component: () => import('@/pages/yfth/referralReward/index'),
    },
    {
      path: 'product-quota',
      name: `${pre}product_quota`,
      meta: {
        auth: ['yfth-product-quota-index'],
        title: '产品额度 / 返货额度',
      },
      component: () => import('@/pages/yfth/productQuota/index'),
    },
  ],
};
