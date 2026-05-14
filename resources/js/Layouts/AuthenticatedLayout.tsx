import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth } = usePage().props;
    const user = auth.user;
    const canUseAbsenceRequests =
        auth.permissions.canViewAbsenceRequests ||
        auth.permissions.canManageAbsenceRequests;
    const absenceRequestsRoute = auth.permissions.canManageAbsenceRequests
        ? 'absence-requests.manage'
        : 'absence-requests.index';
    const canManageLocations = auth.permissions.manageLocations;
    const canViewResidents = auth.permissions.viewResidents;
    const canManageCareReports = auth.permissions.manageCareReports;
    const canManageStaff = auth.permissions.manageStaff;
    const canManagePdlAccounts = auth.permissions.managePdlAccounts;
    const canViewAuditLog = auth.permissions.viewAuditLog;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    return (
        <div className="min-h-screen bg-gray-100">
            <nav className="border-b border-gray-100 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center">
                                <Link href="/">
                                    <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    href={route('dashboard')}
                                    active={route().current('dashboard')}
                                >
                                    Dashboard
                                </NavLink>
                                {canManageLocations && (
                                    <NavLink
                                        href={route('locations.index')}
                                        active={route().current('locations.index')}
                                    >
                                        Wohnbereiche
                                    </NavLink>
                                )}
                                {canManagePdlAccounts && (
                                    <NavLink
                                        href={route('users.index')}
                                        active={route().current('users.index')}
                                    >
                                        PDL-Konten
                                    </NavLink>
                                )}
                                {canViewResidents && (
                                    <NavLink
                                        href={route('residents.index')}
                                        active={route().current('residents.index')}
                                    >
                                        Bewohner
                                    </NavLink>
                                )}
                                {canManageCareReports && (
                                    <NavLink
                                        href={route('care-reports.index')}
                                        active={route().current('care-reports.index')}
                                    >
                                        Pflegeberichte
                                    </NavLink>
                                )}
                                {canUseAbsenceRequests && (
                                    <NavLink
                                        href={route(absenceRequestsRoute)}
                                        active={route().current('absence-requests.*')}
                                    >
                                        Urlaub
                                    </NavLink>
                                )}
                                {auth.permissions.canManageAbsenceRequests && (
                                    <NavLink
                                        href={route('roster-blackout-days.index')}
                                        active={route().current('roster-blackout-days.*')}
                                    >
                                        Urlaubssperren
                                    </NavLink>
                                )}
                                {auth.permissions.canManageAbsenceRequests && (
                                    <NavLink
                                        href={route('shift-templates.index')}
                                        active={route().current('shift-templates.*')}
                                    >
                                        Schichten
                                    </NavLink>
                                )}
                                {canManageStaff && (
                                    <NavLink
                                        href={route('staff.index')}
                                        active={route().current('staff.index')}
                                    >
                                        Mitarbeiter
                                    </NavLink>
                                )}
                                {canViewAuditLog && (
                                    <NavLink
                                        href={route('audit.index')}
                                        active={route().current('audit.index')}
                                    >
                                        Audit-Log
                                    </NavLink>
                                )}
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center">
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            Profile
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            href={route('dashboard')}
                            active={route().current('dashboard')}
                        >
                            Dashboard
                        </ResponsiveNavLink>
                        {canManageLocations && (
                            <ResponsiveNavLink
                                href={route('locations.index')}
                                active={route().current('locations.index')}
                            >
                                Wohnbereiche
                            </ResponsiveNavLink>
                        )}
                        {canManagePdlAccounts && (
                            <ResponsiveNavLink
                                href={route('users.index')}
                                active={route().current('users.index')}
                            >
                                PDL-Konten
                            </ResponsiveNavLink>
                        )}
                        {canViewResidents && (
                            <ResponsiveNavLink
                                href={route('residents.index')}
                                active={route().current('residents.index')}
                            >
                                Bewohner
                            </ResponsiveNavLink>
                        )}
                        {canManageCareReports && (
                            <ResponsiveNavLink
                                href={route('care-reports.index')}
                                active={route().current('care-reports.index')}
                            >
                                Pflegeberichte
                            </ResponsiveNavLink>
                        )}
                        {canUseAbsenceRequests && (
                            <ResponsiveNavLink
                                href={route(absenceRequestsRoute)}
                                active={route().current('absence-requests.*')}
                            >
                                Urlaub
                            </ResponsiveNavLink>
                        )}
                        {auth.permissions.canManageAbsenceRequests && (
                            <ResponsiveNavLink
                                href={route('roster-blackout-days.index')}
                                active={route().current('roster-blackout-days.*')}
                            >
                                Urlaubssperren
                            </ResponsiveNavLink>
                        )}
                        {auth.permissions.canManageAbsenceRequests && (
                            <ResponsiveNavLink
                                href={route('shift-templates.index')}
                                active={route().current('shift-templates.*')}
                            >
                                Schichten
                            </ResponsiveNavLink>
                        )}
                        {canManageStaff && (
                            <ResponsiveNavLink
                                href={route('staff.index')}
                                active={route().current('staff.index')}
                            >
                                Mitarbeiter
                            </ResponsiveNavLink>
                        )}
                        {canViewAuditLog && (
                            <ResponsiveNavLink
                                href={route('audit.index')}
                                active={route().current('audit.index')}
                            >
                                Audit-Log
                            </ResponsiveNavLink>
                        )}
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-gray-500">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-white shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
