import localeLogin from '@/views/login/locale/en-US';
import localeInstallation from '@/views/install/locale/en-US';

import localeWorkplace from '@/views/dashboard/workplace/locale/en-US';
import localeUserSetting from '@/views/user/setting/locale/en-US';

import localeSystemMenu from '@/views/system/menu/locale/en-US';
import localeSystemRole from '@/views/system/role/locale/en-US';
import localeSystemAdmin from '@/views/system/admin/locale/en-US';
import localeSystemDept from '@/views/system/dept/locale/en-US';
import localeSystemJobs from '@/views/system/jobs/locale/en-US';
import localeSystemDict from '@/views/system/dict/locale/en-US';
import localeSystemFile from '@/modules/peanut-file/views/locale/en-US';
import localeSystemCrontab from '@/modules/peanut-task/views/locale/en-US';
import localeSystemMaintenance from '@/views/system/maintenance/locale/en-US';
import localeSystemLog from '@/views/system/log/locale/en-US';
import localeSystemConfig from '@/views/system/config/locale/en-US';
import localeMemberList from '@/modules/peanut-member/views/list/locale/en-US';
import localeMemberTag from '@/modules/peanut-member/views/tag/locale/en-US';
import localeNoticeChannel from '@/modules/peanut-notification/views/channel/locale/en-US';
import localeNoticeTemplate from '@/modules/peanut-notification/views/template/locale/en-US';
import localeNoticeLog from '@/modules/peanut-notification/views/log/locale/en-US';
import localeFinanceAccountLog from '@/modules/peanut-member/views/account-log/locale/en-US';
import localeFinanceRecharge from '@/modules/peanut-payment/views/recharge/locale/en-US';
import localeFinanceRefund from '@/modules/peanut-payment/views/refund/locale/en-US';
import localeAppHotSearch from '@/views/app-setting/hot-search/locale/en-US';
import localeAppPay from '@/modules/peanut-payment/views/settings/locale/en-US';
import localeAppChannel from '@/modules/peanut-oauth/views/channel/locale/en-US';
import localeAppTransaction from '@/views/app-setting/transaction/locale/en-US';
import localeAppReadiness from '@/views/app-setting/readiness/locale/en-US';
import localeArticleCate from '@/modules/peanut-article/views/cate/locale/en-US';
import localeArticleList from '@/modules/peanut-article/views/list/locale/en-US';
import localeConfigurationTransfer from '@/modules/peanut-import-export/views/locale/en-US';
import localeRichText from '@/modules/peanut-rich-text/views/locale/en-US';
import localeOfficialSettings from '@/modules/peanut-settings/locale/en-US';
import localeReferenceCodes from '@/modules/peanut-reference-codes/locale/en-US';
import localeIntegrationSecurity from '@/modules/peanut-integration/runtime/locale/en-US';
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
  'tenantSession.networkError':
    'Network connection failed. Check your connection and try again.',
  'tenantSession.requestTimeout': 'The request timed out. Please try again.',
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
  ...localeOfficialSettings,
  ...localeReferenceCodes,
  ...localeIntegrationSecurity,
};
