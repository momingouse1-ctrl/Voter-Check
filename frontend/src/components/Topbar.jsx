import React from 'react';
import { Menu, Search } from 'lucide-react';
import { getEmail } from '../lib/auth';
import { useNavigate } from 'react-router-dom';

export default function Topbar({ title, onMenuClick }) {
  const email = getEmail();
  const navigate = useNavigate();

  return (
    <header className="topbar">
      <div className="topbar-left">
        <button
          type="button"
          className="topbar-menu-btn"
          onClick={onMenuClick}
          aria-label="Open navigation"
        >
          <Menu size={20} />
        </button>
        <h1 className="topbar-title">{title}</h1>
      </div>
      <div className="topbar-right">
        <button
          className="topbar-search-btn"
          onClick={() => navigate('/search')}
          title="Quick Search"
        >
          <Search size={18} />
          <span>Quick Search</span>
        </button>
        <div className="topbar-user">
          <div className="topbar-avatar">
            {email.charAt(0).toUpperCase()}
          </div>
          <span className="topbar-email">{email}</span>
        </div>
      </div>
    </header>
  );
}
