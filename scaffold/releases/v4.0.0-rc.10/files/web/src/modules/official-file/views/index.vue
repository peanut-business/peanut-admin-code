<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.file']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.file') }}</template>
      <div class="asset-selector-link">
        <el-button @click="$router.push({ name: 'official.file.assets' })">
          Image asset selector
        </el-button>
      </div>
      <el-tabs v-model="activeType" type="card" @change="onTypeChange">
        <el-tab-pane :name="10" :label="$t('systemFile.tab.image')" />
        <el-tab-pane :name="20" :label="$t('systemFile.tab.video')" />
        <el-tab-pane :name="30" :label="$t('systemFile.tab.file')" />
      </el-tabs>

      <el-row :gutter="16" class="body">
        <!-- 左侧分类 -->
        <el-col :span="4">
          <div class="cate-panel">
            <div class="cate-head">
              <span>{{ $t('systemFile.cate.title') }}</span>
              <el-button
                v-permission="['official.file.category.add']"
                link
                size="small"
                @click="handleCateAdd()"
              >
                <template #icon><Plus /></template>
              </el-button>
            </div>
            <ul class="cate-list">
              <li
                :class="{ active: currentCid === '' }"
                @click="selectCate('')"
              >
                <span class="cate-name">{{ $t('systemFile.cate.all') }}</span>
              </li>
              <li
                v-for="c in flatCateList"
                :key="c.id"
                :class="{ active: currentCid === c.id }"
                @click="selectCate(c.id)"
              >
                <span class="cate-name">
                  {{ `${'  '.repeat(c.depth)}${c.name}` }}
                </span>
                <span class="cate-ops" @click.stop>
                  <span v-permission="['official.file.category.add']">
                    <Plus @click="handleCateAdd(c.id)" />
                  </span>
                  <span v-permission="['official.file.category.edit']">
                    <icon-edit @click="handleCateEdit(c)" />
                  </span>
                  <el-popconfirm
                    v-permission="['official.file.category.delete']"
                    :title="$t('systemFile.cate.delete.confirm')"
                    @confirm="handleCateDelete(c)"
                  >
                    <template #reference><Delete /></template>
                  </el-popconfirm>
                </span>
              </li>
            </ul>
          </div>
        </el-col>
        <!-- 右侧文件区 -->
        <el-col :span="20">
          <div class="toolbar">
            <el-space>
              <el-upload
                v-permission="[uploadPermission]"
                :http-request="
                  (options: UploadRequestOptions) =>
                    uploadFile(activeType, options)
                "
                :data="{ cid: String(currentCid === '' ? 0 : currentCid) }"
                :show-file-list="false"
                :accept="acceptMap[activeType]"
                @success="onUploadSuccess"
              >
                <template #trigger>
                  <el-button type="primary">
                    <template #icon><icon-upload /></template>
                    {{ $t('systemFile.op.upload') }}
                  </el-button>
                </template>
              </el-upload>
              <el-input
                v-model="searchName"
                clearable
                style="width: 200px"
                :placeholder="$t('systemFile.search.placeholder')"
                @keyup.enter="() => fetchFiles(1)"
                @clear="() => fetchFiles(1)"
              />
              <el-select
                v-model="searchSource"
                clearable
                style="width: 140px"
                :placeholder="$t('systemFile.search.source')"
                @change="() => fetchFiles(1)"
                @clear="() => fetchFiles(1)"
              >
                <el-option :value="0" :label="$t('systemFile.source.admin')" />
                <el-option :value="1" :label="$t('systemFile.source.user')" />
              </el-select>
              <el-button @click="() => fetchFiles(1)">
                <template #icon><Search /></template>
              </el-button>
            </el-space>
            <el-space v-if="checkedIds.length">
              <span class="selected-tip">
                {{ $t('systemFile.op.selected', { n: checkedIds.length }) }}
              </span>
              <el-button
                v-permission="['official.file.move']"
                size="small"
                @click="openMove"
              >
                {{ $t('systemFile.op.move') }}
              </el-button>
              <el-popconfirm
                v-permission="['official.file.delete']"
                :title="$t('systemFile.op.batchDelete.confirm')"
                @confirm="handleBatchDelete"
              >
                <template #reference
                  ><el-button size="small" type="danger">{{
                    $t('systemFile.op.delete')
                  }}</el-button></template
                >
              </el-popconfirm>
            </el-space>
          </div>

          <div v-loading="loading" style="width: 100%">
            <div v-if="renderData.length" class="grid">
              <div
                v-for="item in renderData"
                :key="item.id"
                class="grid-item"
                :class="{ checked: checkedIds.includes(item.id) }"
              >
                <div class="thumb" @click="toggleCheck(item.id)">
                  <el-checkbox
                    class="thumb-check"
                    :model-value="checkedIds.includes(item.id)"
                    @click.stop
                    @change="() => toggleCheck(item.id)"
                  />
                  <img
                    v-if="activeType === 10"
                    :src="item.url"
                    :alt="item.name"
                  />
                  <div v-else class="thumb-icon">
                    <icon-play-circle v-if="activeType === 20" :size="40" />
                    <icon-file v-else :size="40" />
                  </div>
                </div>
                <div class="name" :title="item.name">{{ item.name }}</div>
                <div class="ops">
                  <el-button link size="small" @click="previewFile(item)">
                    {{ $t('systemFile.op.preview') }}
                  </el-button>
                  <el-button link size="small" @click="copyUrl(item)">
                    {{ $t('systemFile.op.copy') }}
                  </el-button>
                  <el-button
                    v-permission="['official.file.rename']"
                    link
                    size="small"
                    @click="handleRename(item)"
                  >
                    {{ $t('systemFile.op.rename') }}
                  </el-button>
                  <el-popconfirm
                    v-permission="['official.file.delete']"
                    :title="$t('systemFile.op.delete.confirm')"
                    @confirm="handleDelete(item)"
                  >
                    <template #reference
                      ><el-button link size="small" type="danger">{{
                        $t('systemFile.op.delete')
                      }}</el-button></template
                    >
                  </el-popconfirm>
                </div>
              </div>
            </div>
            <el-empty v-else />
          </div>

          <div class="pager">
            <el-pagination
              v-model:current-page="pagination.current"
              v-model:page-size="pagination.pageSize"
              :total="pagination.total"
              layout="prev, pager, next"
              @current-change="fetchFiles"
            />
          </div>
        </el-col>
      </el-row>
    </el-card>
    <!-- 分类新增/编辑 -->
    <el-dialog
      v-model="cateModalVisible"
      :title="
        cateIsEdit
          ? $t('systemFile.cate.editTitle')
          : $t('systemFile.cate.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form
        ref="cateFormRef"
        :model="cateForm"
        :rules="cateRules"
        label-position="top"
      >
        <el-form-item prop="name" :label="$t('systemFile.cate.field.name')">
          <el-input
            v-model="cateForm.name"
            maxlength="20"
            show-word-limit
            :placeholder="$t('systemFile.cate.field.name.placeholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="cateModalVisible = false">取消</el-button
        ><el-button type="primary" :loading="cateSubmitting" @click="submitCate"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <!-- 文件重命名 -->
    <el-dialog
      v-model="renameModalVisible"
      :title="$t('systemFile.op.rename')"
      :close-on-click-modal="false"
    >
      <el-form
        ref="renameFormRef"
        :model="renameForm"
        :rules="renameRules"
        label-position="top"
      >
        <el-form-item prop="name" :label="$t('systemFile.rename.field')">
          <el-input
            v-model="renameForm.name"
            :placeholder="$t('systemFile.rename.placeholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="renameModalVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="renameSubmitting"
          @click="submitRename"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <!-- 移动分类 -->
    <el-dialog
      v-model="moveModalVisible"
      :title="$t('systemFile.op.move')"
      :close-on-click-modal="false"
    >
      <el-form :model="{ moveTarget }" label-position="top">
        <el-form-item :label="$t('systemFile.move.target')">
          <el-select
            v-model="moveTarget"
            :placeholder="$t('systemFile.move.placeholder')"
          >
            <el-option
              :value="0"
              :label="$t('systemFile.cate.uncategorized')"
              >{{ $t('systemFile.cate.uncategorized') }}</el-option
            >
            <el-option
              v-for="c in flatCateList"
              :key="c.id"
              :value="c.id"
              :label="`${'  '.repeat(c.depth)}${c.name}`"
            >
              {{ `${'  '.repeat(c.depth)}${c.name}` }}
            </el-option>
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="moveModalVisible = false">取消</el-button
        ><el-button type="primary" :loading="moveSubmitting" @click="submitMove"
          >保存</el-button
        ></template
      >
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance, UploadRequestOptions } from 'element-plus';
  import { Delete, Plus, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getFileCateList,
    addFileCate,
    editFileCate,
    deleteFileCate,
    getFileList,
    moveFile,
    renameFile,
    deleteFile,
    uploadFile,
    type FileType,
    type FileCateRecord,
    type FileRecord,
  } from '@/modules/official-file/api';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(false);

  const activeType = ref<FileType>(10);
  const acceptMap: Record<FileType, string> = {
    10: 'image/*',
    20: 'video/*',
    30: '',
  };
  const uploadPermissionMap: Record<FileType, string> = {
    10: 'official.file.upload.image',
    20: 'official.file.upload.video',
    30: 'official.file.upload.file',
  };
  const uploadPermission = computed(
    () => uploadPermissionMap[activeType.value]
  );

  // ---- 分类 ----
  const cateList = ref<FileCateRecord[]>([]);
  const flatCateList = computed(() => {
    const result: Array<FileCateRecord & { depth: number }> = [];
    const walk = (items: FileCateRecord[], depth: number) => {
      items.forEach((item) => {
        result.push({ ...item, depth });
        if (item.children?.length) walk(item.children, depth + 1);
      });
    };
    walk(cateList.value, 0);
    return result;
  });
  const currentCid = ref<number | ''>('');

  const fetchCate = async () => {
    const { data } = await getFileCateList(activeType.value);
    cateList.value = data;
  };

  // ---- 文件 ----
  const renderData = ref<FileRecord[]>([]);
  const searchName = ref('');
  const searchSource = ref<number | ''>('');
  const checkedIds = ref<number[]>([]);
  const pagination = reactive({ current: 1, pageSize: 24, total: 0 });

  const fetchFiles = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getFileList({
        type: activeType.value,
        cid: currentCid.value,
        name: searchName.value,
        source: searchSource.value,
        page_no: page,
        page_size: pagination.pageSize,
      });
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
      checkedIds.value = [];
    } finally {
      setLoading(false);
    }
  };

  const init = async () => {
    await fetchCate();
    await fetchFiles(1);
  };
  init();

  const onTypeChange = async () => {
    currentCid.value = '';
    searchName.value = '';
    searchSource.value = '';
    await fetchCate();
    await fetchFiles(1);
  };

  const selectCate = (cid: number | '') => {
    currentCid.value = cid;
    fetchFiles(1);
  };

  const toggleCheck = (id: number) => {
    const i = checkedIds.value.indexOf(id);
    if (i >= 0) checkedIds.value.splice(i, 1);
    else checkedIds.value.push(id);
  };
  // ---- 上传回调 ----
  const onUploadSuccess = () => {
    ElMessage.success(t('systemFile.tip.uploadOk'));
    fetchFiles(pagination.current);
  };

  // ---- 分类弹窗 ----
  const cateModalVisible = ref(false);
  const cateIsEdit = ref(false);
  const cateSubmitting = ref(false);
  const cateFormRef = ref<FormInstance>();
  const cateForm = reactive({ id: 0, pid: 0, name: '' });
  const cateRules = {
    name: [
      { required: true, message: t('systemFile.cate.field.name.required') },
      { max: 20, message: t('systemFile.cate.field.name.max') },
    ],
  };

  const handleCateAdd = (pid = 0) => {
    cateIsEdit.value = false;
    cateForm.id = 0;
    cateForm.pid = pid;
    cateForm.name = '';
    cateModalVisible.value = true;
  };
  const handleCateEdit = (c: FileCateRecord) => {
    cateIsEdit.value = true;
    cateForm.id = c.id;
    cateForm.name = c.name;
    cateModalVisible.value = true;
  };
  const submitCate = async () => {
    const valid = await cateFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    cateSubmitting.value = true;
    try {
      if (cateIsEdit.value) {
        await editFileCate({ id: cateForm.id, name: cateForm.name });
      } else {
        await addFileCate({
          type: activeType.value,
          pid: cateForm.pid,
          name: cateForm.name,
        });
      }
      ElMessage.success(t('systemFile.tip.ok'));
      cateModalVisible.value = false;
      await fetchCate();
    } finally {
      cateSubmitting.value = false;
    }
  };
  const handleCateDelete = async (c: FileCateRecord) => {
    await deleteFileCate(c.id);
    ElMessage.success(t('systemFile.tip.ok'));
    if (currentCid.value === c.id) currentCid.value = '';
    await fetchCate();
    await fetchFiles(1);
  };

  // ---- 重命名 ----
  const renameModalVisible = ref(false);
  const renameSubmitting = ref(false);
  const renameFormRef = ref<FormInstance>();
  const renameForm = reactive({ id: 0, name: '' });
  const renameRules = {
    name: [{ required: true, message: t('systemFile.rename.required') }],
  };
  const handleRename = (item: FileRecord) => {
    renameForm.id = item.id;
    renameForm.name = item.name;
    renameModalVisible.value = true;
  };
  const submitRename = async () => {
    const valid = await renameFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    renameSubmitting.value = true;
    try {
      await renameFile(renameForm.id, renameForm.name);
      ElMessage.success(t('systemFile.tip.ok'));
      renameModalVisible.value = false;
      await fetchFiles(pagination.current);
    } finally {
      renameSubmitting.value = false;
    }
  };

  // ---- 删除 ----
  const handleDelete = async (item: FileRecord) => {
    await deleteFile([item.id]);
    ElMessage.success(t('systemFile.tip.ok'));
    await fetchFiles(pagination.current);
  };
  const handleBatchDelete = async () => {
    await deleteFile([...checkedIds.value]);
    ElMessage.success(t('systemFile.tip.ok'));
    await fetchFiles(pagination.current);
  };

  const previewFile = (item: FileRecord) => {
    window.open(item.url, '_blank', 'noopener,noreferrer');
  };
  const copyUrl = async (item: FileRecord) => {
    await navigator.clipboard.writeText(item.url);
    ElMessage.success(t('systemFile.tip.copied'));
  };

  // ---- 移动 ----
  const moveModalVisible = ref(false);
  const moveSubmitting = ref(false);
  const moveTarget = ref<number>(0);
  const openMove = () => {
    moveTarget.value = 0;
    moveModalVisible.value = true;
  };
  const submitMove = async () => {
    moveSubmitting.value = true;
    try {
      await moveFile([...checkedIds.value], moveTarget.value);
      ElMessage.success(t('systemFile.tip.ok'));
      moveModalVisible.value = false;
      await fetchFiles(pagination.current);
    } finally {
      moveSubmitting.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemFile',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .body {
    margin-top: 8px;
  }

  .asset-selector-link {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 8px;
  }

  .cate-panel {
    border: 1px solid var(--color-neutral-3);
    border-radius: 4px;
    overflow: hidden;
  }

  .cate-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    font-weight: 500;
    border-bottom: 1px solid var(--color-neutral-3);
  }

  .cate-list {
    margin: 0;
    padding: 4px 0;
    list-style: none;
    max-height: 520px;
    overflow-y: auto;

    li {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 12px;
      cursor: pointer;

      &:hover {
        background: var(--color-fill-2);
      }

      &.active {
        background: var(--color-primary-light-1);
        color: rgb(var(--primary-6));
      }
    }

    .cate-name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .cate-ops {
      display: none;
      gap: 8px;

      :deep(svg) {
        cursor: pointer;
      }
    }

    li:hover .cate-ops {
      display: flex;
    }
  }

  .toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
  }

  .selected-tip {
    color: var(--color-text-3);
    font-size: 12px;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 16px;
  }

  .grid-item {
    border: 1px solid var(--color-neutral-3);
    border-radius: 4px;
    overflow: hidden;

    &.checked {
      border-color: rgb(var(--primary-6));
    }
  }

  .thumb {
    position: relative;
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-fill-2);
    cursor: pointer;

    img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
  }

  .thumb-check {
    position: absolute;
    top: 6px;
    left: 6px;
  }

  .thumb-icon {
    color: var(--color-text-3);
  }

  .name {
    padding: 6px 8px;
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ops {
    display: flex;
    justify-content: space-between;
    padding: 0 4px 4px;
    border-top: 1px solid var(--color-neutral-3);
  }

  .pager {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
