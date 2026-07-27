import LayoutMain from '@/layout';
import setting from '@/setting';

const routePre = setting.routePre;

export default {
  path: routePre + '/yfth-finance',
  name: 'yfth_finance',
  header: 'finance',
  redirect: {
    name: 'yfth_finance_withdrawal',
  },
  meta: {
    auth: ['yfth-finance'],
  },
  component: LayoutMain,
  children: [
    {
      path: 'withdrawal',
      name: 'yfth_finance_withdrawal',
      meta: {
        auth: ['yfth-finance-withdrawal-index'],
        title: '提现审核',
      },
      component: () => import('@/pages/yfth/financeWithdrawal/index'),
    },
  ],
};
