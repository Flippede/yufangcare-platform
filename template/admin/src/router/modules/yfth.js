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
  ],
};
