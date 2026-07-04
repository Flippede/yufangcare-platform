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
  ],
};
