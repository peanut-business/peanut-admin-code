# 图表模块

`peanut.chart` 提供 ECharts 6.1.0 / vue-echarts 8.3.0 的管理端公共入口；引擎依赖属于模块，不进入必装 Core。`peanut.chart-examples` 是独立可选包，只含静态示例、菜单和查看权限，单向依赖 `peanut.chart ^1.0`，没有业务表或 API。两个模块分别通过现有 Module/Plugin 链管理。

基础模块进入首次安装默认选择，示例不进入。基础模块声明 `tenant.enableable=false`，安装后按现有部署基础能力合同可用，不是套餐或租户业务开通项；`lifecycle.protected=false` 仍允许在无消费者时退出。源码发现、包安装、租户开通和人员授权仍是独立动作。需要示例时，在现有模块管理页面单独安装、为所需租户开通并授予 `peanut.chart-examples.view`，页面为 `/examples/charts`。不需要示例时不安装；其缺席不影响工作台。

联合开发须显式使用包含「部署基础能力与生命周期保护分离」修正的 PHP Core 源码。源码接入及本地包安装不代表公开 Core 渠道或正式发行资格已完成；正式交付须采用包含该修正的固定 Core 发行版本，再核验最终组合。

## 公共入口

```vue
<template>
  <Chart
    ref="chart"
    :option="option"
    :loading="loading"
    autoresize
    style="width: 100%; height: 320px"
    @click="onClick"
  />
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { Chart, type EChartsOption } from '@peanut-admin/peanut-chart/charts';

  const chart = ref<InstanceType<typeof Chart> | null>(null);
  const loading = ref(false);
  const option: EChartsOption = {
    xAxis: { type: 'category', data: ['A', 'B', 'C'] },
    yAxis: { type: 'value' },
    series: [{ type: 'bar', data: [12, 20, 15] }],
  };
  const onClick = (event: { name?: string }) => console.log(event.name);
  // 挂载后可使用 chart.value?.getDataURL()、dispatchAction() 等原生实例方法。
</script>
```

入口按需登记 Canvas、折线、柱、饼、雷达、散点、仪表盘，以及 grid、tooltip、legend、dataZoom、graphic、title、dataset、aria 组件；没有另一套配置 DSL。直接传 ECharts option。Chart 是原生 vue-echarts 组件的公开重导出，所有 props、slots、事件、实例方法、自动尺寸处理及销毁均由上游维护。容器须有实际高度；隐藏后恢复、加载状态使用原生 `autoresize` 和 `loading`，不另注册 window resize 或手工 dispose。

宿主 `App.vue` 用官方 `THEME_KEY` 提供响应式明暗主题，工作台及未覆盖主题的模块图表自动继承；具体页面可用 `theme` 显式覆盖。ECharts 6 的暗色主题名为 `dark`，恢复默认亮色使用 `default`，不要传未注册的 `light`。实例 ref 类型使用 `InstanceType<typeof Chart>`。上游完整 API、手动更新和其他图形能力见 [vue-echarts 文档](https://github.com/ecomfe/vue-echarts)。新增图形类型时在基础模块的公开入口登记实际所需 ECharts 模块，不向宿主恢复全局 Chart 或重复注册。

业务模块应在自己的 `module.json` 声明 `{"module_key":"peanut.chart","version":"^1.0"}`，在前端 `package.json` 声明 `@peanut-admin/peanut-chart: workspace:^1.0.0` 后导入公共子路径。不要跨模块访问源码内部路径。基础模块不导入示例，公共文档位于本文件，不随示例退出。

## 独立交付与退出

在应用根已配置工具环境后，分别使用原生入口：

```sh
peanut module pack peanut.chart --output=/absolute/release/modules/peanut.chart-1.0.2.tar
peanut module pack peanut.chart-examples --output=/absolute/release/modules/peanut.chart-examples-1.0.0.tar
peanut module install /absolute/release/modules/peanut.chart-1.0.2.tar --sha256=<archive-sha256>
peanut module install /absolute/release/modules/peanut.chart-examples-1.0.0.tar --sha256=<archive-sha256>
peanut module disable peanut.chart-examples
peanut module uninstall peanut.chart-examples
```

install 的来源摘要取对应制包输出，不把例子中的占位符当实际参数。后续安装示例前先安装其基础依赖；已有源码仍走同一原生包入口，不另设子插件系统。uninstall 先返回精确预览，按现有 UI 确认或将返回的 `confirm_plan` 保存为 JSON，再以 `--confirm-plan-file=<file> --confirm-plan-digest=<digest>` 执行同一操作。不加 `--purge` 时是 retire：退出该包源码、锁和原生依赖，重建受影响前端；不是仅隐藏菜单。保留的恢复现场按原服务管理。

当前宿主工作台及主题入口直接依赖基础包，`web/package.json` 明确声明该依赖。原生命周期反向依赖检查同时阻止此时停用/卸载基础模块，返回 `host:web->peanut.chart`；示例自己的已安装依赖也会阻止基础退出。若定制产品要移除基础，先移除实际宿主调用和显式依赖，并退出所有依赖它的业务模块，再走同一卸载入口。宿主 Composer 中由安装器自动装配的 Module require 不作为宿主业务依赖；后端投影没有 Web 源码时不读取 Web 文件。
