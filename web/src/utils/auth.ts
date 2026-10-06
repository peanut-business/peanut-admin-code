const TOKEN_KEY = 'token';
let sessionGeneration = 0;
let observedToken: string | null = null;
let hasObservedToken = false;

const readStoredToken = () => localStorage.getItem(TOKEN_KEY);

const rememberToken = (token: string | null) => {
  observedToken = token;
  hasObservedToken = true;
};

const isLogin = () => {
  return !!localStorage.getItem(TOKEN_KEY);
};

const getToken = () => {
  const token = readStoredToken();
  if (!hasObservedToken) {
    rememberToken(token);
  } else if (token !== observedToken) {
    rememberToken(token);
    sessionGeneration += 1;
  }
  return token;
};

const setToken = (token: string) => {
  localStorage.setItem(TOKEN_KEY, token);
  rememberToken(token);
};

const clearToken = () => {
  localStorage.removeItem(TOKEN_KEY);
  rememberToken(null);
  sessionGeneration += 1;
};

const advanceSessionGeneration = () => {
  sessionGeneration += 1;
  return sessionGeneration;
};

const getSessionSnapshot = () => {
  const token = getToken();
  return { generation: sessionGeneration, token };
};

export {
  isLogin,
  getToken,
  setToken,
  clearToken,
  advanceSessionGeneration,
  getSessionSnapshot,
};
