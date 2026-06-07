// Auth helpers - email gate only
export const getEmail = () => localStorage.getItem('pnf_email') || '';
export const isAdminUser = () => localStorage.getItem('pnf_is_admin') === 'true';

export const setAuth = (email, isAdmin) => {
  localStorage.setItem('pnf_email', email);
  localStorage.setItem('pnf_is_admin', isAdmin ? 'true' : 'false');
};

export const clearAuth = () => {
  localStorage.removeItem('pnf_email');
  localStorage.removeItem('pnf_is_admin');
};

export const isLoggedIn = () => !!getEmail();
