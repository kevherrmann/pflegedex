type SearchFieldProps = {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    className?: string;
    ariaLabel?: string;
};

/**
 * Einheitliches Such-/Filterfeld mit Lupen-Icon und Löschen-Button.
 * Filtert clientseitig – die aufrufende Seite hält die Logik.
 */
export default function SearchField({
    value,
    onChange,
    placeholder = 'Suchen …',
    className = '',
    ariaLabel,
}: SearchFieldProps) {
    return (
        <div className={`relative ${className}`}>
            <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                <svg
                    className="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={1.8}
                    stroke="currentColor"
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
                    />
                </svg>
            </span>
            <input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                aria-label={ariaLabel ?? placeholder}
                className="block w-full rounded-md border-gray-300 pl-10 pr-9 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
            />
            {value !== '' && (
                <button
                    type="button"
                    onClick={() => onChange('')}
                    aria-label="Suche zurücksetzen"
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 transition hover:text-gray-600"
                >
                    <svg
                        className="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        strokeWidth={1.8}
                        stroke="currentColor"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            )}
        </div>
    );
}
