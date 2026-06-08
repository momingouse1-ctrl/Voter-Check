import React, { useEffect, useState } from 'react';
import Sidebar from './Sidebar';
import Topbar from './Topbar';

export default function AppLayout({ children, title = 'PDF Name Finder' }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => {
    document.body.classList.toggle('nav-open', sidebarOpen);

    return () => {
      document.body.classList.remove('nav-open');
    };
  }, [sidebarOpen]);

  return (
    <div className="app-layout">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <button
        type="button"
        className={`sidebar-backdrop ${sidebarOpen ? 'open' : ''}`}
        aria-label="Close navigation"
        onClick={() => setSidebarOpen(false)}
      />
      <div className="main-content">
        <Topbar title={title} onMenuClick={() => setSidebarOpen(true)} />
        <main className="page-body">
          {children}
        </main>
      </div>
    </div>
  );
}
