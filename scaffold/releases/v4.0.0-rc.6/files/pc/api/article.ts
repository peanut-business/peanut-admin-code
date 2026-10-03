export type ArticleRequestClient = {
  get<T = unknown>(
    url: string,
    params?: Record<string, unknown>,
    auth?: boolean
  ): Promise<T>;
  post<T = unknown>(
    url: string,
    body?: Record<string, unknown> | null,
    auth?: boolean
  ): Promise<T>;
};

export interface PageData<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface Article {
  id: number;
  cid: number;
  cate_name?: string;
  title: string;
  image: string;
  desc: string;
  abstract?: string;
  author?: string;
  click: number;
  create_time: string;
  collect?: boolean;
}

export interface ArticleDetail extends Article {
  content: string;
}

export interface ArticleCategory {
  id: number;
  name: string;
}

export interface ArticleCollectionItem {
  id: number;
  title: string;
  image: string;
  desc: string;
  click: number;
  create_time: string;
  collect_time: string;
}

export interface ArticleListParams {
  cid?: number;
  keyword?: string;
  pageNo?: number;
  pageSize?: number;
}

interface ArticleCollectionWireItem extends Omit<ArticleCollectionItem, 'id'> {
  id: number;
  article_id: number;
}

function publicArticle<T extends Article>(article: T): Omit<T, 'collect'> {
  const { collect: _collect, ...publicFields } = article;
  return publicFields;
}

export function getPcIndex<TDecoration>(client: ArticleRequestClient) {
  return client
    .get<{
      all?: Article[];
      article?: Article[];
      decorate?: TDecoration;
    }>('api/pc/index', undefined, false)
    .then((data) => ({
      ...data,
      all: data.all?.map(publicArticle),
      article: data.article?.map(publicArticle),
    }));
}

export function getArticleCategories(client: ArticleRequestClient) {
  return client.get<ArticleCategory[]>('api/article/cate', undefined, false);
}

export function getArticles(
  client: ArticleRequestClient,
  params: ArticleListParams = {}
) {
  return client
    .get<PageData<Article>>(
      'api/article/lists',
      {
        cid: params.cid,
        keyword: params.keyword,
        page_no: params.pageNo,
        page_size: params.pageSize,
      },
      false
    )
    .then((page) => ({ ...page, lists: page.lists.map(publicArticle) }));
}

export function getArticleDetail(client: ArticleRequestClient, id: number) {
  return client
    .get<ArticleDetail & { collect?: boolean }>(
      'api/article/detail',
      { id },
      false
    )
    .then(publicArticle);
}

/** 只有明确的不存在响应转为空状态；服务、权限、网络和解析异常继续交给页面错误处理。 */
export async function getArticleDetailOrNull(
  client: ArticleRequestClient,
  id: number
) {
  try {
    return await getArticleDetail(client, id);
  } catch (error) {
    if (
      typeof error === 'object' &&
      error !== null &&
      'kind' in error &&
      error.kind === 'business' &&
      'code' in error &&
      error.code === '40400'
    )
      return null;
    throw error;
  }
}

export function addArticleCollect(client: ArticleRequestClient, id: number) {
  return client.post('api/article/addCollect', { id });
}

export function cancelArticleCollect(client: ArticleRequestClient, id: number) {
  return client.post('api/article/cancelCollect', { id });
}

export async function getArticleCollections(
  client: ArticleRequestClient,
  pageNo = 1,
  pageSize = 12
): Promise<PageData<ArticleCollectionItem>> {
  const page = await client.get<PageData<ArticleCollectionWireItem>>(
    'api/article/collect',
    { page_no: pageNo, page_size: pageSize }
  );
  return {
    ...page,
    lists: page.lists.map(({ article_id: id, ...item }) => ({ ...item, id })),
  };
}
