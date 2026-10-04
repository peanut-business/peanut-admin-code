import { isMultiTenantDeployment as coreIsMultiTenantDeployment } from '@peanut-admin/vue';

/** Application environment adapter for the framework-neutral Tenant session contract. */
const isMultiTenantDeployment = (): boolean =>
  coreIsMultiTenantDeployment(import.meta.env.VITE_DEPLOYMENT_MODE);

export default isMultiTenantDeployment;
