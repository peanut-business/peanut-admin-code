import localeLogin from '@/views/login/locale/zh-CN';
import localeInstallation from '@/views/installation/locale/zh-CN';

import localeWorkplace from '@/views/dashboard/workplace/locale/zh-CN';
import localeUserSetting from '@/views/user/setting/locale/zh-CN';

import localeSystemMenu from '@/views/system/menu/locale/zh-CN';
import localeSystemRole from '@/views/system/role/locale/zh-CN';
import localeSystemAdmin from '@/views/system/admin/locale/zh-CN';
import localeSystemDept from '@/views/system/dept/locale/zh-CN';
import localeSystemJobs from '@/views/system/jobs/locale/zh-CN';
import localeSystemDict from '@/views/system/dict/locale/zh-CN';
import localeSystemFile from '@/modules/official-file/views/locale/zh-CN';
import localeSystemCrontab from '@/modules/official-task/views/locale/zh-CN';
import localeSystemMaintenance from '@/views/system/maintenance/locale/zh-CN';
import localeSystemLog from '@/views/system/log/locale/zh-CN';
import localeSystemConfig from '@/views/system/config/locale/zh-CN';
import localeMemberList from '@/modules/official-member/views/list/locale/zh-CN';
import localeMemberTag from '@/modules/official-member/views/tag/locale/zh-CN';
import localeNoticeChannel from '@/modules/official-notification/views/channel/locale/zh-CN';
import localeNoticeTemplate from '@/modules/official-notification/views/template/locale/zh-CN';
import localeNoticeLog from '@/modules/official-notification/views/log/locale/zh-CN';
import localeFinanceAccountLog from '@/modules/official-member/views/account-log/locale/zh-CN';
import localeFinanceRecharge from '@/modules/official-payment/views/recharge/locale/zh-CN';
import localeFinanceRefund from '@/modules/official-payment/views/refund/locale/zh-CN';
import localeAppHotSearch from '@/views/app-setting/hot-search/locale/zh-CN';
import localeAppPay from '@/modules/official-payment/views/settings/locale/zh-CN';
import localeAppChannel from '@/modules/official-oauth/views/channel/locale/zh-CN';
import localeAppTransaction from '@/views/app-setting/transaction/locale/zh-CN';
import localeAppReadiness from '@/views/app-setting/readiness/locale/zh-CN';
import localeArticleCate from '@/modules/official-article/views/cate/locale/zh-CN';
import localeArticleList from '@/modules/official-article/views/list/locale/zh-CN';
import localeConfigurationTransfer from '@/modules/official-import-export/views/locale/zh-CN';
import localeRichText from '@/modules/official-rich-text/views/locale/zh-CN';
import localeSettings from './zh-CN/settings';

export default {
  'menu.dashboard': '仪表盘',
  'menu.user': '个人中心',
  'menu.appSetting.website': '网站设置',
  'menu.appSetting.user': '用户设置',
  'menu.decoration': '装修管理',
  'menu.decoration.mobile': '移动端装修',
  'menu.decoration.tabbar': 'Tabbar 装修',
  'menu.decoration.pc': 'PC 装修',
  'menu.devTools': '开发工具',
  'menu.devTools.code': '代码生成器',
  'menu.devTools.modules': '模块治理',
  'navbar.action.locale': '切换为中文',
  'navbar.userMenu': '打开用户菜单',
  'navbar.userSettings': '用户设置',
  'navbar.logout': '退出登录',
  'navbar.tenantSwitch': '切换租户',
  'navbar.tenantSelect': '请选择租户',
  'navbar.cancel': '取消',
  'navbar.confirm': '确认',
  ...localeSettings,
  ...localeLogin,
  ...localeInstallation,
  ...localeWorkplace,
  ...localeUserSetting,
  ...localeSystemMenu,
  ...localeSystemRole,
  ...localeSystemAdmin,
  ...localeSystemDept,
  ...localeSystemJobs,
  ...localeSystemDict,
  ...localeSystemFile,
  ...localeSystemCrontab,
  ...localeSystemMaintenance,
  ...localeSystemLog,
  ...localeSystemConfig,
  ...localeMemberList,
  ...localeMemberTag,
  ...localeNoticeChannel,
  ...localeNoticeTemplate,
  ...localeNoticeLog,
  ...localeFinanceAccountLog,
  ...localeFinanceRecharge,
  ...localeFinanceRefund,
  ...localeAppHotSearch,
  ...localeAppPay,
  ...localeAppChannel,
  ...localeAppTransaction,
  ...localeAppReadiness,
  ...localeArticleCate,
  ...localeArticleList,
  ...localeConfigurationTransfer,
  ...localeRichText,
};
