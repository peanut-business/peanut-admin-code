import localeLogin from '@/views/login/locale/en-US';
import localeInstallation from '@/views/installation/locale/en-US';

import localeWorkplace from '@/views/dashboard/workplace/locale/en-US';
import localeUserSetting from '@/views/user/setting/locale/en-US';

import localeSystemMenu from '@/views/system/menu/locale/en-US';
import localeSystemRole from '@/views/system/role/locale/en-US';
import localeSystemAdmin from '@/views/system/admin/locale/en-US';
import localeSystemDept from '@/views/system/dept/locale/en-US';
import localeSystemJobs from '@/views/system/jobs/locale/en-US';
import localeSystemDict from '@/views/system/dict/locale/en-US';
import localeSystemFile from '@/modules/official-file/views/locale/en-US';
import localeSystemCrontab from '@/modules/official-task/views/locale/en-US';
import localeSystemMaintenance from '@/views/system/maintenance/locale/en-US';
import localeSystemLog from '@/views/system/log/locale/en-US';
import localeSystemConfig from '@/views/system/config/locale/en-US';
import localeMemberList from '@/modules/official-member/views/list/locale/en-US';
import localeMemberTag from '@/modules/official-member/views/tag/locale/en-US';
import localeNoticeChannel from '@/modules/official-notification/views/channel/locale/en-US';
import localeNoticeTemplate from '@/modules/official-notification/views/template/locale/en-US';
import localeNoticeLog from '@/modules/official-notification/views/log/locale/en-US';
import localeFinanceAccountLog from '@/modules/official-member/views/account-log/locale/en-US';
import localeFinanceRecharge from '@/modules/official-payment/views/recharge/locale/en-US';
import localeFinanceRefund from '@/modules/official-payment/views/refund/locale/en-US';
import localeAppHotSearch from '@/views/app-setting/hot-search/locale/en-US';
import localeAppPay from '@/modules/official-payment/views/settings/locale/en-US';
import localeAppChannel from '@/modules/official-oauth/views/channel/locale/en-US';
import localeAppTransaction from '@/views/app-setting/transaction/locale/en-US';
import localeAppReadiness from '@/views/app-setting/readiness/locale/en-US';
import localeArticleCate from '@/modules/official-article/views/cate/locale/en-US';
import localeArticleList from '@/modules/official-article/views/list/locale/en-US';
import localeConfigurationTransfer from '@/modules/official-import-export/views/locale/en-US';
import localeRichText from '@/modules/official-rich-text/views/locale/en-US';
import localeSettings from './en-US/settings';

export default {
  'menu.dashboard': 'Dashboard',
  'menu.user': 'User Center',
  'menu.appSetting.website': 'Website Settings',
  'menu.appSetting.user': 'User Settings',
  'menu.decoration': 'Decoration',
  'menu.decoration.mobile': 'Mobile Decoration',
  'menu.decoration.tabbar': 'Tabbar Decoration',
  'menu.decoration.pc': 'PC Decoration',
  'menu.devTools': 'Developer Tools',
  'menu.devTools.code': 'Code Generator',
  'menu.devTools.modules': 'Module Governance',
  'navbar.action.locale': 'Switch to English',
  'navbar.userMenu': 'Open user menu',
  'navbar.userSettings': 'User Settings',
  'navbar.logout': 'Logout',
  'navbar.tenantSwitch': 'Switch Tenant',
  'navbar.tenantSelect': 'Select a Tenant',
  'navbar.cancel': 'Cancel',
  'navbar.confirm': 'Confirm',
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
