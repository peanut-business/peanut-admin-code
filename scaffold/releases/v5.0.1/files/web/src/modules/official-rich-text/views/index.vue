<template>
  <div class="container">
    <Breadcrumb :items="['menu.richText', 'menu.richText.documents']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.richText.documents') }}</template>
      <el-form inline @submit.prevent="fetchData(1)">
        <el-form-item :label="$t('richText.search.title')">
          <el-input v-model="keyword" clearable />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="fetchData(1)">{{
            $t('richText.action.search')
          }}</el-button>
          <el-button @click="reset">{{
            $t('richText.action.reset')
          }}</el-button>
        </el-form-item>
      </el-form>
      <el-button
        v-permission="['official.rich-text.document.add']"
        type="primary"
        style="margin-bottom: 16px"
        @click="openAdd"
        >{{ $t('richText.action.add') }}</el-button
      >
      <el-table v-loading="loading" :data="documents" row-key="id" border>
        <el-table-column prop="title" :label="$t('richText.column.title')" />
        <el-table-column
          prop="revision"
          :label="$t('richText.column.revision')"
          width="100"
        />
        <el-table-column :label="$t('richText.column.updatedAt')" width="190">
          <template #default="{ row }">{{
            formatTime(row.update_time)
          }}</template>
        </el-table-column>
        <el-table-column
          :label="$t('richText.column.operations')"
          width="150"
          fixed="right"
        >
          <template #default="{ row }">
            <el-button
              v-permission="['official.rich-text.document.edit']"
              link
              type="primary"
              @click="openEdit(row)"
              >{{ $t('richText.action.edit') }}</el-button
            >
            <el-popconfirm
              v-permission="['official.rich-text.document.delete']"
              :title="$t('richText.confirm.delete')"
              @confirm="remove(row.id)"
            >
              <template #reference>
                <el-button link type="danger">{{
                  $t('richText.action.delete')
                }}</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? $t('richText.dialog.edit') : $t('richText.dialog.add')"
      width="min(1000px, 92vw)"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="title" :label="$t('richText.form.title')">
          <el-input v-model="form.title" maxlength="200" show-word-limit />
        </el-form-item>
        <el-form-item :label="$t('richText.form.content')">
          <RichTextEditor
            :key="editorKey"
            v-model="form.document"
            v-model:collaboration-state="form.collaboration_state"
            :collaboration="collaboration"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import useLoading from '@/hooks/loading';
  import { RichTextEditor } from '../src/editor';
  import { emptyDocument, type RichTextDocumentValue } from '../src/document';
  import type { RichTextCollaborationConfig } from '../components/types';
  import {
    addRichTextDocument,
    deleteRichTextDocument,
    editRichTextDocument,
    getRichTextCollaboration,
    getRichTextDocument,
    getRichTextDocuments,
    type RichTextDocumentRecord,
  } from '../api';

  interface DocumentForm {
    id: number;
    title: string;
    revision: number;
    document: RichTextDocumentValue;
    collaboration_state: string;
  }

  const blankForm = (): DocumentForm => ({
    id: 0,
    title: '',
    revision: 0,
    document: emptyDocument(),
    collaboration_state: '',
  });
  const { loading, setLoading } = useLoading(true);
  const documents = ref<RichTextDocumentRecord[]>([]);
  const keyword = ref('');
  const pagination = reactive({ current: 1, pageSize: 15, total: 0 });
  const dialogVisible = ref(false);
  const formRef = ref<FormInstance>();
  const form = ref<DocumentForm>(blankForm());
  const collaboration = ref<RichTextCollaborationConfig | null>(null);
  const editorKey = ref('new');
  const rules: FormRules = {
    title: [
      { required: true, max: 200, message: '请输入不超过 200 个字符的标题' },
    ],
  };

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getRichTextDocuments({
        title: keyword.value || undefined,
        page_no: page,
        page_size: pagination.pageSize,
      });
      documents.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const reset = () => {
    keyword.value = '';
    fetchData(1);
  };

  const openAdd = () => {
    form.value = blankForm();
    collaboration.value = null;
    editorKey.value = `new-${Date.now()}`;
    dialogVisible.value = true;
  };

  const openEdit = async (row: RichTextDocumentRecord) => {
    const [{ data }, { data: collaborationData }] = await Promise.all([
      getRichTextDocument(row.id),
      getRichTextCollaboration(row.id),
    ]);
    form.value = {
      id: data.id,
      title: data.title,
      revision: data.revision,
      document: data.document || emptyDocument(),
      collaboration_state: data.collaboration_state || '',
    };
    collaboration.value = collaborationData;
    editorKey.value = `document-${data.id}-${data.revision}`;
    dialogVisible.value = true;
  };

  const save = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    if (form.value.id) await editRichTextDocument(form.value);
    else await addRichTextDocument(form.value);
    ElMessage.success('保存成功');
    dialogVisible.value = false;
    fetchData(pagination.current);
  };

  const remove = async (id: number) => {
    await deleteRichTextDocument(id);
    ElMessage.success('删除成功');
    fetchData(pagination.current);
  };

  const formatTime = (value: number) =>
    value ? new Date(value * 1000).toLocaleString() : '-';

  fetchData();
</script>

<script lang="ts">
  export default { name: 'RichTextDocuments' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }
  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
