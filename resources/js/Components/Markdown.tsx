import ReactMarkdown, { type Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';

/**
 * Rendert KI-/Freitext-Markdown (Fett, Überschriften, Listen, Tabellen) sauber
 * mit Tailwind-Styling – statt rohen ** und # im Text anzuzeigen.
 */
const COMPONENTS: Components = {
    p: ({ children }) => <p className="text-gray-800">{children}</p>,
    strong: ({ children }) => <strong className="font-semibold text-gray-900">{children}</strong>,
    em: ({ children }) => <em className="italic">{children}</em>,
    ul: ({ children }) => <ul className="list-disc space-y-1 pl-5">{children}</ul>,
    ol: ({ children }) => <ol className="list-decimal space-y-1 pl-5">{children}</ol>,
    li: ({ children }) => <li className="text-gray-800">{children}</li>,
    h1: ({ children }) => <h4 className="text-base font-semibold text-gray-900">{children}</h4>,
    h2: ({ children }) => <h4 className="text-base font-semibold text-gray-900">{children}</h4>,
    h3: ({ children }) => <h5 className="font-semibold text-gray-900">{children}</h5>,
    h4: ({ children }) => <h5 className="font-semibold text-gray-900">{children}</h5>,
    a: ({ href, children }) => (
        <a href={href} className="text-[#9B1C3B] underline">
            {children}
        </a>
    ),
    blockquote: ({ children }) => (
        <blockquote className="border-l-4 border-gray-200 pl-3 text-gray-600">
            {children}
        </blockquote>
    ),
    code: ({ children }) => (
        <code className="rounded bg-gray-100 px-1 py-0.5 text-[0.85em]">{children}</code>
    ),
    hr: () => <hr className="border-gray-200" />,
    table: ({ children }) => (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">{children}</table>
        </div>
    ),
    th: ({ children }) => (
        <th className="px-2 py-1 text-left font-semibold text-gray-700">{children}</th>
    ),
    td: ({ children }) => <td className="px-2 py-1 text-gray-800">{children}</td>,
};

export default function Markdown({
    content,
    className = '',
}: {
    content: string;
    className?: string;
}) {
    return (
        <div className={`space-y-2 text-sm leading-6 ${className}`}>
            <ReactMarkdown remarkPlugins={[remarkGfm]} components={COMPONENTS}>
                {content}
            </ReactMarkdown>
        </div>
    );
}
