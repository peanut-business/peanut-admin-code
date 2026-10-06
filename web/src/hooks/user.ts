import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';

import { useUserStore } from '@/store';
import { getSessionSnapshot } from '@/utils/auth';

export default function useUser() {
  const router = useRouter();
  const userStore = useUserStore();
  const logout = async (logoutTo?: string) => {
    const session = getSessionSnapshot();
    const cleared = await userStore.logout(session);
    if (!cleared) return;
    const afterLogout = getSessionSnapshot();
    if (
      afterLogout.generation !== session.generation + 2 ||
      afterLogout.token !== null
    ) return;
    const currentRoute = router.currentRoute.value;
    ElMessage.success('登出成功');
    await router.push({
      name: logoutTo && typeof logoutTo === 'string' ? logoutTo : 'login',
      query: {
        ...router.currentRoute.value.query,
        redirect:
          typeof currentRoute.name === 'string' ? currentRoute.name : undefined,
      },
    });
  };
  return {
    logout,
  };
}
