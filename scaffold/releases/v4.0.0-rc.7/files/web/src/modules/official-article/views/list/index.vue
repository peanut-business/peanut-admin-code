<template>
  <div class="container">
    <Breadcrumb :items="['menu.article', 'menu.article.list']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.article.list') }}</template>
      <el-form :model="formModel" label-position="top">
        <el-row :gutter="16">
          <el-col :span="7">
            <el-form-item prop="title" :label="$t('article.form.title')">
              <el-input
                v-model="formModel.title"
                clearable
                :placeholder="$t('article.form.title.placeholder')"
              />
            </el-form-item>
          </el-col>
          <el-col :span="7">
            <el-form-item prop="cid" :label="$t('article.form.cate')">
              <el-select
                v-model="formModel.cid"
                clearable
                :placeholder="$t('article.form.cate.placeholder')"
                style="width: 100%"
                ><el-option
                  v-for="item in cateOptions"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
              /></el-select>
            </el-form-item>
          </el-col>
          <el-col :span="7">
            <el-form-item prop="is_show" :label="$t('article.form.isShow')">
              <el-select
                v-model="formModel.is_show"
                clearable
                :placeholder="$t('article.form.isShow.placeholder')"
                style="width: 100%"
                ><el-option
                  v-for="item in showOptions"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
              /></el-select>
            </el-form-item>
          </el-col>
          <el-col :span="3" class="filter-actions"
            ><el-space direction="vertical" :size="12"
              ><el-button type="primary" :icon="Search" @click="search">{{
                $t('article.form.search')
              }}</el-button
              ><el-button :icon="Refresh" @click="reset">{{
                $t('article.form.reset')
              }}</el-button></el-space
            ></el-col
          >
        </el-row>
      </el-form>
      <el-divider style="margin-top: 0" />
      <el-button
        v-permission="['official.article.add']"
        type="primary"
        :icon="Plus"
        style="margin-bottom: 16px"
        @click="openAdd"
      >
        {{ $t('article.button.add') }}
      </el-button>
      <el-table v-loading="loading" row-key="id" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('article.columns.id')"
          width="70"
        />
        <el-table-column :label="$t('article.columns.image')" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.image"
              :src="row.image"
              width="60"
              height="40"
              fit="cover"
            />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="title" :label="$t('article.columns.title')" />
        <el-table-column
          prop="cate_name"
          :label="$t('article.columns.cate')"
          width="120"
        />
        <el-table-column
          prop="author"
          :label="$t('article.columns.author')"
          width="100"
        />
        <el-table-column
          prop="click"
          :label="$t('article.columns.click')"
          width="100"
        />
        <el-table-column
          prop="sort"
          :label="$t('article.columns.sort')"
          width="80"
        />
        <el-table-column :label="$t('article.columns.isShow')" width="90"
          ><template #default="{ row }"
            ><el-switch
              v-permission="['official.article.update-status']"
              :model-value="row.is_show === 1"
              @change="(value: boolean) => onStatusChange(row, value)" /></template
        ></el-table-column>
        <el-table-column :label="$t('article.columns.createTime')" width="170"
          ><template #default="{ row }">{{
            formatTime(row.create_time)
          }}</template></el-table-column
        >
        <el-table-column
          :label="$t('article.columns.operations')"
          width="150"
          fixed="right"
          ><template #default="{ row }"
            ><el-space>
              <el-button
                v-permission="['official.article.edit']"
                link
                type="primary"
                size="small"
                @click="openEdit(row)"
              >
                {{ $t('article.button.edit') }}
              </el-button>
              <el-popconfirm
                v-permission="['official.article.delete']"
                :title="$t('article.confirm.delete')"
                @confirm="onDelete(row)"
              >
                <template #reference
                  ><el-button link type="danger" size="small">
                    {{ $t('article.button.delete') }}
                  </el-button></template
                >
              </el-popconfirm>
            </el-space></template
          ></el-table-column
        >
      </el-table>
      <div class="pagination-wrapper"
        ><el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="onPageChange"
      /></div>

      <el-dialog
        v-model="modalVisible"
        :title="form.id ? $t('article.modal.edit') : $t('article.modal.add')"
        width="900px"
        @closed="closeModal"
      >
        <el-form ref="formRef" :model="form" :rules="rules" label-width="auto">
          <el-form-item prop="cid" :label="$t('article.field.cate')">
            <el-select
              v-model="form.cid"
              :placeholder="$t('article.form.cate.placeholder')"
              style="width: 100%"
              ><el-option
                v-for="item in cateOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
            /></el-select>
          </el-form-item>
          <el-form-item prop="title" :label="$t('article.field.title')">
            <el-input
              v-model="form.title"
              :maxlength="64"
              show-word-limit
              :placeholder="$t('article.form.title.placeholder')"
            />
          </el-form-item>
          <el-form-item prop="desc" :label="$t('article.field.desc')">
            <el-input
              type="textarea"
              v-model="form.desc"
              :maxlength="200"
              show-word-limit
              :autosize="{ minRows: 2, maxRows: 4 }"
            />
          </el-form-item>
          <el-form-item prop="abstract" :label="$t('article.field.abstract')">
            <el-input
              type="textarea"
              v-model="form.abstract"
              :maxlength="200"
              show-word-limit
              :autosize="{ minRows: 2, maxRows: 4 }"
            />
          </el-form-item>
          <el-form-item prop="image" :label="$t('article.field.image')">
            <el-space alignment="flex-end">
              <el-upload
                :http-request="
                  (options: UploadRequestOptions) => uploadFile(10, options)
                "
                :show-file-list="false"
                accept="image/*"
                :on-success="onCoverSuccess"
              >
                <div class="img-uploader">
                  <img v-if="form.image" :src="form.image" alt="cover" />
                  <el-icon v-else><Plus /></el-icon>
                </div>
              </el-upload>
              <file-picker
                :type="10"
                :limit="1"
                button-text="从素材库选择"
                @select="onCoverSelected"
              />
            </el-space>
          </el-form-item>
          <el-form-item prop="author" :label="$t('article.field.author')"
            ><el-input v-model="form.author"
          /></el-form-item>
          <el-form-item prop="sort" :label="$t('article.field.sort')"
            ><el-input-number v-model="form.sort" :min="0" :max="9999"
          /></el-form-item>
          <el-form-item
            prop="click_virtual"
            :label="$t('article.field.clickVirtual')"
          >
            <el-input-number v-model="form.click_virtual" :min="0" />
          </el-form-item>
          <el-form-item prop="is_show" :label="$t('article.field.isShow')">
            <el-switch
              v-model="form.is_show"
              :active-value="1"
              :inactive-value="0"
            />
          </el-form-item>
          <el-form-item prop="content" :label="$t('article.field.content')">
            <div class="rich-editor">
              <div class="rich-editor__toolbar">
                <el-tooltip content="粗体">
                  <el-button
                    size="small"
                    @mousedown.prevent="formatContent('bold')"
                    ><b>B</b></el-button
                  >
                </el-tooltip>
                <el-tooltip content="斜体">
                  <el-button
                    size="small"
                    @mousedown.prevent="formatContent('italic')"
                  >
                    <i>I</i>
                  </el-button>
                </el-tooltip>
                <el-tooltip content="无序列表">
                  <el-button
                    size="small"
                    @mousedown.prevent="formatContent('insertUnorderedList')"
                  >
                    <el-icon><List /></el-icon>
                  </el-button>
                </el-tooltip>
                <el-upload
                  :http-request="
                    (options: UploadRequestOptions) => uploadFile(10, options)
                  "
                  :show-file-list="false"
                  accept="image/*"
                  :before-upload="beforeContentUpload"
                  :on-success="
                    (file: FileRecord) => onContentMediaSuccess(file, 'image')
                  "
                >
                  <el-tooltip content="插入图片"
                    ><el-button size="small" aria-label="插入图片"
                      ><el-icon><Picture /></el-icon></el-button
                  ></el-tooltip>
                </el-upload>
                <file-picker
                  :type="10"
                  :limit="20"
                  size="mini"
                  button-text="素材图片"
                  @open="saveContentRange"
                  @select="(urls) => onContentMaterialSelected(urls, 'image')"
                />
                <el-upload
                  :http-request="
                    (options: UploadRequestOptions) => uploadFile(20, options)
                  "
                  :show-file-list="false"
                  accept="video/*"
                  :before-upload="beforeContentUpload"
                  :on-success="
                    (file: FileRecord) => onContentMediaSuccess(file, 'video')
                  "
                >
                  <el-tooltip content="插入视频"
                    ><el-button size="small" aria-label="插入视频"
                      ><el-icon><VideoCamera /></el-icon></el-button
                  ></el-tooltip>
                </el-upload>
                <file-picker
                  :type="20"
                  :limit="10"
                  size="mini"
                  button-text="素材视频"
                  @open="saveContentRange"
                  @select="(urls) => onContentMaterialSelected(urls, 'video')"
                />
              </div>
              <div
                ref="contentEditorRef"
                class="rich-editor__content"
                contenteditable="true"
                @input="onContentInput"
                @paste.prevent="onContentPaste"
                @keyup="saveContentRange"
                @mouseup="saveContentRange"
              ></div>
            </div>
          </el-form-item>
        </el-form>
        <template #footer
          ><el-button @click="closeModal">取消</el-button
          ><el-button type="primary" @click="onSubmit"
            >确定</el-button
          ></template
        >
      </el-dialog>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { computed, nextTick, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import {
    ElMessage,
    type FormInstance,
    type FormRules,
    type UploadRequestOptions,
  } from 'element-plus';
  import {
    List,
    Picture,
    Plus,
    Refresh,
    Search,
    VideoCamera,
  } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import sanitizeRichText from '@/utils/sanitize-rich-text';
  import FilePicker from '@/components/file-picker/index.vue';
  import { uploadFile, type FileRecord } from '@/modules/official-file/api';
  import {
    getArticleList,
    getArticleCateAll,
    getArticleDetail,
    addArticle,
    editArticle,
    deleteArticle,
    updateArticleStatus,
    type ArticleRecord,
    type ArticleListParams,
  } from '@/modules/official-article/api';

  interface ArticleForm {
    id: number;
    cid: number | undefined;
    title: string;
    desc: string;
    abstract: string;
    image: string;
    author: string;
    content: string;
    click_virtual: number;
    sort: number;
    is_show: number;
  }

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<ArticleRecord[]>([]);
  const cateOptions = ref<Array<{ label: string; value: number }>>([]);

  const generateFormModel = () => ({
    title: '',
    cid: '' as number | string,
    is_show: '' as number | string,
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const showOptions = computed(() => [
    { label: t('article.show.1'), value: 1 },
    { label: t('article.show.0'), value: 0 },
  ]);

  const formatTime = (value?: number | string) => {
    if (!value) return '-';
    if (typeof value === 'number') {
      return new Date(value * 1000).toLocaleString();
    }
    return value;
  };

  const fetchCateOptions = async () => {
    const { data } = await getArticleCateAll();
    cateOptions.value = data.map((item) => ({
      label: item.name,
      value: item.id,
    }));
  };
  fetchCateOptions();

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const params: ArticleListParams = {
        title: formModel.value.title || undefined,
        cid: formModel.value.cid === '' ? undefined : formModel.value.cid,
        is_show:
          formModel.value.is_show === '' ? undefined : formModel.value.is_show,
        page_no: page,
        page_size: pagination.pageSize,
        page_type: 1,
      };
      const { data } = await getArticleList(params);
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const search = () => fetchData(1);
  const onPageChange = (current: number) => fetchData(current);
  const reset = () => {
    formModel.value = generateFormModel();
    fetchData(1);
  };

  const formRef = ref<FormInstance>();
  const contentEditorRef = ref<HTMLDivElement>();
  const contentRange = ref<Range>();
  const modalVisible = ref(false);
  const generateForm = (): ArticleForm => ({
    id: 0,
    cid: undefined,
    title: '',
    desc: '',
    abstract: '',
    image: '',
    author: '',
    content: '',
    click_virtual: 0,
    sort: 0,
    is_show: 1,
  });
  const form = ref<ArticleForm>(generateForm());

  const validateRange =
    (min: number, max?: number) =>
    (_rule: unknown, value: number, callback: (error?: Error) => void) => {
      const number = Number(value);
      if (
        !Number.isInteger(number) ||
        number < min ||
        (max !== undefined && number > max)
      ) {
        callback(
          new Error(
            max === undefined
              ? t('article.validation.nonNegative')
              : t('article.validation.sort')
          )
        );
        return;
      }
      callback();
    };

  const rules: FormRules = {
    cid: [{ required: true, message: t('article.form.cate.placeholder') }],
    title: [
      { required: true, message: t('article.form.title.placeholder') },
      {
        max: 64,
        message: t('article.validation.titleLength'),
      },
    ],
    desc: [{ max: 200, message: t('article.validation.textLength') }],
    abstract: [{ max: 200, message: t('article.validation.textLength') }],
    sort: [{ validator: validateRange(0, 9999) }],
    click_virtual: [{ validator: validateRange(0) }],
  };

  const syncEditor = async () => {
    await nextTick();
    if (contentEditorRef.value) {
      const sanitizedContent = sanitizeRichText(form.value.content);
      form.value.content = sanitizedContent;
      contentEditorRef.value.innerHTML = sanitizedContent;
    }
    formRef.value?.clearValidate();
  };

  const readEditorContent = (): string => {
    const editor = contentEditorRef.value;
    const content = editor?.innerHTML ?? form.value.content;
    const sanitizedContent = sanitizeRichText(content);
    if (editor && editor.innerHTML !== sanitizedContent) {
      editor.innerHTML = sanitizedContent;
    }
    return sanitizedContent;
  };

  const openAdd = async () => {
    contentRange.value = undefined;
    form.value = generateForm();
    modalVisible.value = true;
    await syncEditor();
  };

  const openEdit = async (record: ArticleRecord) => {
    contentRange.value = undefined;
    const { data } = await getArticleDetail(record.id);
    form.value = {
      id: data.id,
      cid: data.cid,
      title: data.title,
      desc: data.desc || '',
      abstract: data.abstract || '',
      image: data.image || '',
      author: data.author || '',
      content: sanitizeRichText(data.content || ''),
      click_virtual: data.click_virtual || 0,
      sort: data.sort || 0,
      is_show: data.is_show,
    };
    modalVisible.value = true;
    await syncEditor();
  };

  const onContentInput = () => {
    form.value.content = readEditorContent();
    saveContentRange();
  };

  const onContentPaste = (event: ClipboardEvent) => {
    const clipboard = event.clipboardData;
    if (!clipboard) return;
    const html = clipboard.getData('text/html');
    const text = clipboard.getData('text/plain');
    const safe = html
      ? sanitizeRichText(html)
      : sanitizeRichText(text).replace(/\r?\n/g, '<br>');
    document.execCommand('insertHTML', false, safe);
    form.value.content = readEditorContent();
    saveContentRange();
  };

  const saveContentRange = () => {
    const editor = contentEditorRef.value;
    const selection = window.getSelection();
    if (!editor || !selection?.rangeCount) return;
    const range = selection.getRangeAt(0);
    if (editor.contains(range.commonAncestorContainer)) {
      contentRange.value = range.cloneRange();
    }
  };

  const beforeContentUpload = () => {
    saveContentRange();
    return true;
  };

  const formatContent = (command: string) => {
    contentEditorRef.value?.focus();
    document.execCommand(command);
    if (contentEditorRef.value) {
      form.value.content = readEditorContent();
    }
  };

  const onCoverSuccess = (file: FileRecord) => {
    form.value.image = file.url || file.uri;
    ElMessage.success(t('article.tip.uploadSuccess'));
  };

  const onCoverSelected = (urls: string[]) => {
    form.value.image = urls[0] || '';
  };

  const insertContentMedia = (url: string, type: 'image' | 'video') => {
    const editor = contentEditorRef.value;
    if (!url || !editor) return;

    const media = document.createElement(type === 'image' ? 'img' : 'video');
    media.setAttribute('src', url);
    if (type === 'video') {
      media.setAttribute('controls', 'controls');
      media.setAttribute('preload', 'metadata');
    }

    const range = contentRange.value;
    if (range && editor.contains(range.commonAncestorContainer)) {
      range.deleteContents();
      range.insertNode(media);
      range.setStartAfter(media);
      range.collapse(true);
      contentRange.value = range;
    } else {
      editor.appendChild(media);
    }
    form.value.content = readEditorContent();
  };

  const onContentMaterialSelected = (
    urls: string[],
    type: 'image' | 'video'
  ) => {
    urls.forEach((url) => insertContentMedia(url, type));
  };

  const onContentMediaSuccess = (file: FileRecord, type: 'image' | 'video') => {
    insertContentMedia(file.url || file.uri, type);
    ElMessage.success(t('article.tip.uploadSuccess'));
  };

  const closeModal = () => {
    contentRange.value = undefined;
    modalVisible.value = false;
  };

  const onSubmit = async () => {
    form.value.content = readEditorContent();
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    if (form.value.id) {
      await editArticle(form.value);
    } else {
      await addArticle(form.value);
    }
    ElMessage.success(t('article.message.success'));
    modalVisible.value = false;
    fetchData(pagination.current);
  };

  const onDelete = async (record: ArticleRecord) => {
    await deleteArticle(record.id);
    ElMessage.success(t('article.message.success'));
    fetchData(pagination.current);
  };

  const onStatusChange = async (record: ArticleRecord, value: unknown) => {
    const isShow = value ? 1 : 0;
    await updateArticleStatus(record.id, isShow);
    record.is_show = isShow;
    ElMessage.success(t('article.message.success'));
  };
</script>

<script lang="ts">
  export default {
    name: 'ArticleList',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .img-uploader {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;

    img {
      max-width: 100%;
      max-height: 100%;
    }
  }

  .rich-editor {
    width: 100%;
    overflow: hidden;
    border: 1px solid var(--el-border-color);
    border-radius: var(--el-border-radius-small);

    &__toolbar {
      display: flex;
      gap: 8px;
      padding: 8px;
      background: var(--el-fill-color-light);
      border-bottom: 1px solid var(--el-border-color);
    }

    &__content {
      min-height: 220px;
      max-height: 420px;
      padding: 12px;
      overflow-y: auto;
      line-height: 1.6;
      outline: none;

      :deep(img),
      :deep(video) {
        max-width: 100%;
        height: auto;
      }
    }
  }

  .filter-actions {
    display: flex;
    justify-content: flex-end;
    padding-bottom: 18px;
  }
  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
