"use client";

import { Check, ChevronDown, Search } from "lucide-react";
import { KeyboardEvent, useEffect, useId, useMemo, useRef, useState } from "react";
import type { LocationOption } from "./gym-location-options";

type SearchableSelectProps = {
  label: string;
  name: string;
  options: LocationOption[];
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
};

export function SearchableSelect({
  label,
  name,
  options,
  value,
  onChange,
  placeholder = "Search…",
}: SearchableSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const rootRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const listboxId = useId();
  const labelId = useId();
  const selected = options.find((option) => option.value === value);
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return options;

    return options.filter((option) => `${option.label} ${option.searchText ?? ""}`.toLowerCase().includes(needle));
  }, [options, query]);

  useEffect(() => {
    if (!open) return;
    const closeOnOutsideClick = (event: MouseEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", closeOnOutsideClick);
    requestAnimationFrame(() => searchRef.current?.focus());
    return () => document.removeEventListener("mousedown", closeOnOutsideClick);
  }, [open]);

  function choose(nextValue: string) {
    onChange(nextValue);
    setQuery("");
    setOpen(false);
  }

  function handleSearchKeys(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Escape") {
      event.preventDefault();
      setOpen(false);
    } else if (event.key === "Enter" && filtered[0]) {
      event.preventDefault();
      choose(filtered[0].value);
    }
  }

  return <div className="searchable-select-label"><span className="searchable-select-caption" id={labelId}>{label}</span>
    <input type="hidden" name={name} value={value} />
    <div className={`searchable-select${open ? " open" : ""}`} ref={rootRef}>
      <button
        type="button"
        className="searchable-select-trigger"
        role="combobox"
        aria-labelledby={labelId}
        aria-controls={listboxId}
        aria-expanded={open}
        aria-haspopup="listbox"
        onClick={() => setOpen((current) => !current)}
      >
        <span>{selected?.label ?? "Select an option"}</span><ChevronDown size={15} />
      </button>
      {open && <div className="searchable-select-popover">
        <div className="searchable-select-search"><Search size={14} /><input ref={searchRef} value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={handleSearchKeys} placeholder={placeholder} aria-label={`Search ${label.toLowerCase()}`} /></div>
        <div className="searchable-select-options" id={listboxId} role="listbox" aria-labelledby={labelId}>
          {filtered.map((option) => <button key={option.value} type="button" role="option" aria-selected={option.value === value} onClick={() => choose(option.value)}>
            <span>{option.label}</span>{option.value === value && <Check size={14} />}
          </button>)}
          {filtered.length === 0 && <p>No matching option</p>}
        </div>
      </div>}
    </div>
  </div>;
}
