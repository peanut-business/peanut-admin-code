import { createSessionSync, type SessionSyncType } from './session-sync';
import {
  newBrowserSessionIdentity,
  readBrowserSession,
  writeBrowserSession,
} from './session-storage';

const TOKEN_KEY = 'token';
const SESSION_KEY = 'peanut-admin-session';
let sessionGeneration = 0;
let acceptedIdentity =
  typeof window === 'undefined'
    ? null
    : readBrowserSession(SESSION_KEY, TOKEN_KEY).identity;
let remoteIdentityChanged = false;

// Switch to @peanut-admin/client after the locked release exports this helper.
const sessionSync = createSessionSync('admin', ({ type }) => {
  if (type !== 'session-refreshed') window.location.reload();
});
import.meta.hot?.dispose(sessionSync.dispose);

const getToken = () => {
  const { identity, token } = readBrowserSession(SESSION_KEY, TOKEN_KEY);
  if (identity !== acceptedIdentity) {
    if (!remoteIdentityChanged) sessionGeneration += 1;
    remoteIdentityChanged = true;
  }
  // Old tenant UI cannot borrow a new bearer while its reload event is pending.
  return remoteIdentityChanged ? null : token;
};

const isLogin = () => getToken() !== null;
const getSessionSnapshot = () => {
  const token = getToken();
  return { generation: sessionGeneration, token };
};

const assertSessionGeneration = (expected: number) => {
  if (getSessionSnapshot().generation !== expected || remoteIdentityChanged) {
    throw new Error('The request session has changed.');
  }
};

const commit = (token: string | null, type: SessionSyncType) => {
  assertSessionGeneration(sessionGeneration);
  const transitionsIdentity = type !== 'session-refreshed';
  const identity = transitionsIdentity
    ? newBrowserSessionIdentity()
    : acceptedIdentity;
  writeBrowserSession(SESSION_KEY, TOKEN_KEY, { identity, token });
  if (transitionsIdentity) {
    acceptedIdentity = identity;
    sessionGeneration += 1;
  }
  sessionSync.publish(type);
};

const setToken = (token: string, type: Exclude<SessionSyncType, 'logout'>) =>
  commit(token, type);
const clearToken = () => commit(null, 'logout');

export {
  assertSessionGeneration,
  isLogin,
  getToken,
  setToken,
  clearToken,
  getSessionSnapshot,
};
