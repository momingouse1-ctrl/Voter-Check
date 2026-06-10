import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { isLoggedIn, isAdminUser } from './lib/auth';

import EmailGate      from './pages/EmailGate';
import Dashboard      from './pages/Dashboard';
import UploadPage     from './pages/UploadPage';
import SearchPage     from './pages/SearchPage';
import PdfLibrary     from './pages/PdfLibrary';
import FailedFiles    from './pages/FailedFiles';
import Settings       from './pages/Settings';
import EmailsPage     from './pages/EmailsPage';
import ExcelImportPage from './pages/ExcelImportPage';


// Protected route wrapper
function Protected({ children }) {
  return isLoggedIn() ? children : <Navigate to="/" replace />;
}

// Admin only wrapper
function AdminOnly({ children }) {
  return isAdminUser() ? children : <Navigate to="/search" replace />;
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<EmailGate />} />
        <Route path="/dashboard" element={<Protected><AdminOnly><Dashboard /></AdminOnly></Protected>} />
        <Route path="/upload"        element={<Protected><AdminOnly><UploadPage /></AdminOnly></Protected>} />
        <Route path="/excel-import"  element={<Protected><AdminOnly><ExcelImportPage /></AdminOnly></Protected>} />
        <Route path="/search"    element={<Protected><SearchPage /></Protected>} />
        <Route path="/pdfs"      element={<Protected><AdminOnly><PdfLibrary /></AdminOnly></Protected>} />
        <Route path="/failed"    element={<Protected><AdminOnly><FailedFiles /></AdminOnly></Protected>} />
        <Route path="/emails"    element={<Protected><AdminOnly><EmailsPage /></AdminOnly></Protected>} />
        <Route path="/settings"  element={<Protected><AdminOnly><Settings /></AdminOnly></Protected>} />
        {/* Catch-all */}
        <Route path="*" element={<Navigate to={isLoggedIn() ? (isAdminUser() ? '/dashboard' : '/search') : '/'} replace />} />
      </Routes>
    </BrowserRouter>
  );
}
