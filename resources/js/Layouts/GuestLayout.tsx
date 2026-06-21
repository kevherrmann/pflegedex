import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-[#F8F8F8] pt-6 sm:justify-center sm:pt-0">
            <div>
                <Link href="/">
                    <img
                        src="/logo/pflegedex-horizontal.svg"
                        alt="Pflegedex"
                        className="h-16 w-auto"
                    />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
