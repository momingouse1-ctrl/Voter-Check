import React from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard, Upload, Search, FileText,
  AlertTriangle, Settings, LogOut, BookOpen, Mail
} from 'lucide-react';
import { getEmail, clearAuth, isAdminUser } from '../lib/auth';

const navItems = [
  { to: '/dashboard',  label: 'Dashboard',     icon: LayoutDashboard, adminOnly: true },
  { to: '/upload',     label: 'Upload PDFs',    icon: Upload,          adminOnly: true },
  { to: '/search',     label: 'Search Names',   icon: Search,          adminOnly: false },
  { to: '/pdfs',       label: 'PDF Library',    icon: BookOpen,        adminOnly: true },
  { to: '/emails',     label: 'Emails',         icon: Mail,            adminOnly: true },
  { to: '/failed',     label: 'Failed Files',   icon: AlertTriangle,   adminOnly: true },
  { to: '/settings',   label: 'Settings',       icon: Settings,        adminOnly: true },
];

export default function Sidebar({ isOpen = false, onClose }) {
  const location = useLocation();
  const navigate = useNavigate();
  const email = getEmail();
  const isAdmin = isAdminUser();

  const handleLogout = () => {
    clearAuth();
    onClose?.();
    navigate('/');
  };

  return (
    <aside className={`sidebar ${isOpen ? 'open' : ''}`} aria-label="Primary navigation">
      {/* Logo */}
      <div className="sidebar-logo">
        <div className="sidebar-logo-icon">
          <FileText size={22} />
        </div>
        <div>
          <div className="sidebar-logo-name">PDF Name Finder</div>
          <div className="sidebar-logo-sub">Voter List Search</div>
        </div>
      </div>

      {/* Nav */}
      <nav className="sidebar-nav">
        {navItems.filter(item => !item.adminOnly || isAdmin).map(({ to, label, icon: Icon }) => {
          const active = location.pathname === to;
          return (
            <Link
              key={to}
              to={to}
              className={`sidebar-link ${active ? 'active' : ''}`}
              onClick={onClose}
            >
              <Icon size={18} />
              <span>{label}</span>
            </Link>
          );
        })}
      </nav>

      {/* Footer */}
      <div className="sidebar-footer">
        <div className="sidebar-email">{email}</div>
        <button className="sidebar-logout" onClick={handleLogout}>
          <LogOut size={16} />
          <span>Sign Out</span>
        </button>
      </div>
    </aside>
  );
}
