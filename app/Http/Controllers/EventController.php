<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    /**
     * GET /api/events
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            // Public: hanya published
            return response()->json([
                'success' => true,
                'data' => Event::all()
            ]);
        }

        // Admin: lihat semua
        if ($user->hasRole('Admin')) {
            $events = Event::with('users')->get();
        }
        // EO: hanya event yang dia manage (owner)
        elseif ($user->hasRole('EO')) {
            $events = $user->ownedEvents()->with('users')->get();
        }
        // User: lihat semua
        else {
            $events = Event::all();
        }

        return response()->json([
            'success' => true,
            'data' => $events
        ]);
    }

    /**
     * GET /api/events/{slug}
     * Support both slug and ID for backward compatibility
     */
    public function show($slug)
    {
        // Try to find by slug first
        $event = Event::with('users', 'jenisTiket')
            ->where('slug', $slug)
            ->first();

        // If not found, try by ID (backward compatibility)
        if (!$event && is_numeric($slug)) {
            $event = Event::with('users', 'jenisTiket')->find($slug);
        }

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $event
        ]);
    }

    /**
     * POST /api/events
     * 
     * Flow:
     * 1. EO creates event
     * 2. Auto-assign EO as owner (is_owner = 1)
     * 3. No approval needed (langsung published)
     * 
     * ⚠️ Middleware: permission:event.create (EO + Admin)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        Log::info('EVENT.CREATE ATTEMPT', [
            'user_id' => $user->id_user,
            'user_roles' => $user->getRoleNames()->toArray(),
            'can_event_create' => $user->can('event.create'),
            'has_permission_to' => $user->hasPermissionTo('event.create'),
            'all_permissions' => $user->getAllPermissions()->pluck('name')->toArray()
        ]);

        if (!$user->hasAnyRole(['Admin', 'EO'])) {
            Log::warning('BLOCKED: User attempted to create event without proper role', [
                'user_id' => $user->id_user,
                'roles' => $user->getRoleNames()->toArray(),
                'required_roles' => ['Admin', 'EO']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only Admin and EO can create events'
            ], 403);
        }

        if (!$user->can('event.create')) {
            Log::warning('BLOCKED: User failed permission check', [
                'user_id' => $user->id_user,
                'permission' => 'event.create'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to create events'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nama_event' => 'required|string|max:255',
            'deskripsi'  => 'nullable|string',
            'lokasi'     => 'required|string|max:255',
            'start_time' => 'required|date',
            'end_time'   => 'required|date|after_or_equal:start_time',
            'berbayar'   => 'required|boolean',
            'banner'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Upload banner
        $bannerPath = null;
        if ($request->hasFile('banner')) {
            $banner = $request->file('banner');
            $filename = uniqid() . '.' . $banner->getClientOriginalExtension();
            $banner->storeAs('banners', $filename, 'public');
            $bannerPath = 'banners/' . $filename;
        }


        // Create event (langsung published, no approval)
        $event = Event::create([
            'nama_event' => $request->nama_event,
            'deskripsi'  => $request->deskripsi,
            'lokasi'     => $request->lokasi,
            'start_time' => $request->start_time,
            'end_time'   => $request->end_time,
            'berbayar'   => $request->berbayar,
            'banner'     => $bannerPath,
        ]);

        // ✅ Auto-assign creator as owner
        $event->users()->attach($user->id_user, ['is_owner' => 1]);

        Log::info('EVENT.CREATED with owner', [
            'event_id' => $event->id_event,
            'owner_id' => $user->id_user,
            'owner_name' => $user->nama
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event created successfully',
            'data' => $event->load('users')
        ], 201);
    }

    /**
     * GET /api/events/my-assigned
     * 
     * Get event yang di-assign ke user (khusus untuk Panitia)
     * Hanya menampilkan event dimana user adalah panitia (is_owner = 0)
     * 
     * Middleware: auth, jwt.db
     */
    public function getMyAssignedEvents()
    {
        try {
            $user = auth()->user();

            // Get events where user is assigned (as panitia, not owner)
            $events = DB::table('user_has_event')
                ->join('event', 'user_has_event.id_event', '=', 'event.id_event')
                ->where('user_has_event.id_user', $user->id_user)
                ->where('user_has_event.is_owner', 0) // ✅ HANYA PANITIA
                ->select(
                    'event.id_event',
                    'event.nama_event',
                    'event.deskripsi',
                    'event.lokasi',
                    'event.start_time',
                    'event.end_time',
                    'event.banner',
                    'event.berbayar',
                    'event.created_at',
                    'event.updated_at'
                )
                ->orderBy('event.start_time', 'desc')
                ->get();

            // Format response
            $formattedEvents = $events->map(function ($event) {
                return [
                    'id_event' => $event->id_event,
                    'nama_event' => $event->nama_event,
                    'deskripsi' => $event->deskripsi,
                    'lokasi' => $event->lokasi,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'banner' => $event->banner ? url('storage/' . $event->banner) : null,
                    'berbayar' => (bool) $event->berbayar,
                    'my_role' => 'Panitia',
                    'created_at' => $event->created_at,
                    'updated_at' => $event->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Assigned events retrieved successfully',
                'data' => [
                    'events' => $formattedEvents,
                    'total' => $formattedEvents->count(),
                    'user_role' => 'Panitia'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching assigned events: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve assigned events',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/check-panitia-access
     * 
     * Cek apakah user memiliki akses sebagai Panitia
     * Return: boolean dan list event yang di-assign
     * 
     * Middleware: auth, jwt.db
     */
    public function checkPanitiaAccess()
    {
        try {
            $user = auth()->user();

            // ✅ LUMEN FIX: Query manual untuk check role Panitia
            $isPanitia = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_type', 'App\Models\User') // atau 'App\\User'
                ->where('model_has_roles.model_id', $user->id_user)
                ->where('roles.name', 'Panitia')
                ->exists();

            Log::info('CHECK_PANITIA_ACCESS', [
                'user_id' => $user->id_user,
                'user_name' => $user->nama,
                'has_panitia_role' => $isPanitia
            ]);

            // Cek apakah user di-assign ke event manapun (sebagai panitia, bukan owner)
            $assignedEventsCount = DB::table('user_has_event')
                ->where('id_user', $user->id_user)
                ->where('is_owner', 0)
                ->count();

            Log::info('ASSIGNED_EVENTS_COUNT', [
                'user_id' => $user->id_user,
                'count' => $assignedEventsCount
            ]);

            // ✅ Get all user roles (manual query)
            $userRoles = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_type', 'App\Models\User')
                ->where('model_has_roles.model_id', $user->id_user)
                ->pluck('roles.name')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'has_panitia_role' => $isPanitia,
                    'has_assigned_events' => $assignedEventsCount > 0,
                    'assigned_events_count' => $assignedEventsCount,
                    'can_access_panitia_page' => $isPanitia && $assignedEventsCount > 0,
                    'user' => [
                        'id_user' => $user->id_user,
                        'nama' => $user->nama,
                        'email' => $user->email,
                        'roles' => $userRoles
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking panitia access: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to check panitia access',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/events/{id}
     * 
     * ✅ Only owner or Admin can update
     * ⚠️ Middleware: permission:event.update
     */
    public function update(Request $request, $id)
    {
        $event = Event::with('users')->find($id);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }

        $user = $request->user();

        // ✅ OWNERSHIP CHECK
        if (!$user->canManageEvent($event)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only update your own events'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nama_event' => 'nullable|string|max:255',
            'deskripsi'  => 'nullable|string',
            'lokasi'     => 'nullable|string|max:255',
            'start_time' => 'nullable|date',
            'end_time'   => 'nullable|date|after_or_equal:start_time',
            'berbayar'   => 'nullable|boolean',
            'banner'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update data
        $data = array_filter($request->only([
            'nama_event',
            'deskripsi',
            'lokasi',
            'start_time',
            'end_time',
            'berbayar',
        ]), fn($v) => !is_null($v));

        // Update banner
        if ($request->hasFile('banner')) {

            // Hapus file lama
            if ($event->banner && Storage::disk('public')->exists($event->banner)) {
                Storage::disk('public')->delete($event->banner);
            }

            $banner = $request->file('banner');
            $filename = uniqid() . '.' . $banner->getClientOriginalExtension();
            $banner->storeAs('banners', $filename, 'public');
            $data['banner'] = 'banners/' . $filename;
        }


        $event->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully',
            'data' => $event->fresh()->load('users')
        ]);
    }

    /**
     * DELETE /api/events/{id}
     * 
     * ✅ Only owner or Admin can delete
     * ⚠️ Middleware: permission:event.delete
     */
    public function destroy(Request $request, $id)
    {
        $event = Event::with('users')->find($id);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }

        $user = $request->user();

        // ✅ OWNERSHIP CHECK
        if (!$user->canManageEvent($event)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own events'
            ], 403);
        }

        // Delete banner
        if ($event->banner && Storage::disk('public')->exists($event->banner)) {
            Storage::disk('public')->delete($event->banner);
        }

        // Detach all users
        $event->users()->detach();

        // Delete event
        $event->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event deleted successfully'
        ]);
    }

    /**
     * GET /api/events/public
     * Public access untuk menampilkan event dengan jenis tiket
     */
    public function publicEvents()
    {
        try {
            $events = Event::with(['jenisTiket' => function ($query) {
                $query->orderBy('harga', 'asc');
            }])
                ->where('start_time', '>=', Carbon::now())
                ->orderBy('start_time', 'asc')
                ->get();

            $formattedEvents = $events->map(function ($event) {
                $data = [
                    'id_event' => $event->id_event,
                    'slug' => $event->slug, // ✅ PENTING: Include slug
                    'nama_event' => $event->nama_event,
                    'deskripsi' => $event->deskripsi,
                    'lokasi' => $event->lokasi,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'banner' => $event->banner,
                    'berbayar' => (bool) $event->berbayar,
                    'created_at' => $event->created_at,
                    'updated_at' => $event->updated_at,
                ];

                if ($event->jenisTiket) {
                    $data['jenis_tiket'] = $event->jenisTiket->map(function ($tiket) {
                        return [
                            'id_jenis_tiket' => $tiket->id_jenis_tiket,
                            'nama_tiket' => $tiket->nama_tiket,
                            'harga' => (float) $tiket->harga,
                            'kuota' => $tiket->kuota,
                        ];
                    });
                } else {
                    $data['jenis_tiket'] = [];
                }

                return $data;
            });

            return response()->json([
                'success' => true,
                'data' => $formattedEvents
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching public events: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load events',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/events/{id}/jenis-tiket
     */
    public function getJenisTiketByEvent($id)
    {
        $event = Event::with('jenisTiket')->find($id);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id_event' => $event->id_event,
                    'nama_event' => $event->nama_event,
                ],
                'jenis_tiket' => $event->jenisTiket
            ]
        ]);
    }

    // ============================================
    // 🆕 PANITIA MANAGEMENT METHODS
    // ============================================

    /**
     * POST /api/events/{id}/add-panitia
     * 
     * EO Owner menambahkan panitia ke event (is_owner = 0)
     * ✅ Only owner or Admin can add panitia
     */
    public function addPanitia(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:user,id_user'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $event = Event::findOrFail($id);
            $user = $request->user();

            // ✅ CHECK: Hanya owner event yang bisa add panitia
            if (!$event->isOwnedBy($user) && !$user->hasAnyRole(['Admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only event owner can add panitia to this event'
                ], 403);
            }

            $panitiaUser = User::findOrFail($request->user_id);

            // Check if user already assigned to this event
            $existing = DB::table('user_has_event')
                ->where('id_user', $request->user_id)
                ->where('id_event', $id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already assigned to this event as ' .
                        ($existing->is_owner ? 'Owner' : 'Panitia')
                ], 400);
            }

            // ✅ ASSIGN USER SEBAGAI PANITIA (is_owner = 0)
            $event->users()->attach($request->user_id, [
                'is_owner' => 0
            ]);

            Log::info('Panitia added to event', [
                'event_id' => $id,
                'panitia_id' => $request->user_id,
                'panitia_name' => $panitiaUser->nama,
                'added_by' => $user->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Panitia added to event successfully',
                'data' => [
                    'event_id' => $id,
                    'event_name' => $event->nama_event,
                    'panitia' => [
                        'id_user' => $panitiaUser->id_user,
                        'nama' => $panitiaUser->nama,
                        'email' => $panitiaUser->email,
                        'role_in_event' => 'Panitia'
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding panitia to event: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to add panitia',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/events/{id}/remove-panitia
     * 
     * EO Owner menghapus panitia dari event
     * ✅ Only owner or Admin can remove panitia
     */
    public function removePanitia(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:user,id_user'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $event = Event::findOrFail($id);
            $user = $request->user();

            // Check: Hanya owner event yang bisa remove panitia
            if (!$event->isOwnedBy($user) && !$user->hasAnyRole(['Admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only event owner can remove panitia from this event'
                ], 403);
            }

            // Check if user is panitia (not owner)
            $assignment = DB::table('user_has_event')
                ->where('id_user', $request->user_id)
                ->where('id_event', $id)
                ->first();

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not assigned to this event'
                ], 400);
            }

            if ($assignment->is_owner == 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove event owner. Transfer ownership first.'
                ], 400);
            }

            // Remove panitia
            $event->users()->detach($request->user_id);

            Log::info('Panitia removed from event', [
                'event_id' => $id,
                'panitia_id' => $request->user_id,
                'removed_by' => $user->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Panitia removed from event successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing panitia from event: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove panitia',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/events/{id}/panitia
     * 
     * Get list panitia untuk event tertentu
     * ✅ Only owner or Admin can view
     */
    public function getEventPanitia(Request $request, $id)
    {
        try {
            $event = Event::findOrFail($id);
            $user = $request->user();

            // Check authorization
            if (!$event->isOwnedBy($user) && !$user->hasAnyRole(['Admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only event owner can view panitia list'
                ], 403);
            }

            // Get all users assigned to this event
            $eventUsers = DB::table('user_has_event')
                ->join('user', 'user_has_event.id_user', '=', 'user.id_user')
                ->where('user_has_event.id_event', $id)
                ->select(
                    'user.id_user',
                    'user.nama',
                    'user.email',
                    'user.phone',
                    'user.photo',
                    'user_has_event.is_owner'
                )
                ->get();

            $owner = $eventUsers->where('is_owner', 1)->first();
            $panitia = $eventUsers->where('is_owner', 0)->values();

            return response()->json([
                'success' => true,
                'message' => 'Event team retrieved successfully',
                'data' => [
                    'event_id' => $id,
                    'event_name' => $event->nama_event,
                    'owner' => $owner ? [
                        'id_user' => $owner->id_user,
                        'nama' => $owner->nama,
                        'email' => $owner->email,
                        'phone' => $owner->phone,
                        'photo' => $owner->photo,
                        'role' => 'Owner'
                    ] : null,
                    'panitia' => $panitia->map(function ($p) {
                        return [
                            'id_user' => $p->id_user,
                            'nama' => $p->nama,
                            'email' => $p->email,
                            'phone' => $p->phone,
                            'photo' => $p->photo,
                            'role' => 'Panitia'
                        ];
                    }),
                    'total_panitia' => $panitia->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching event panitia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve event team',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/events/my-managed
     * 
     * Get semua event yang user kelola (sebagai owner atau panitia)
     */
    public function getMyManagedEvents()
    {
        try {
            $user = auth()->user();

            $events = DB::table('user_has_event')
                ->join('event', 'user_has_event.id_event', '=', 'event.id_event')
                ->where('user_has_event.id_user', $user->id_user)
                ->select(
                    'event.*',
                    'user_has_event.is_owner'
                )
                ->orderBy('event.start_time', 'desc')
                ->get();

            $events = $events->map(function ($event) {
                return [
                    'id_event' => $event->id_event,
                    'nama_event' => $event->nama_event,
                    'deskripsi' => $event->deskripsi,
                    'lokasi' => $event->lokasi,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'banner' => $event->banner,
                    'berbayar' => $event->berbayar,
                    'my_role' => $event->is_owner ? 'Owner' : 'Panitia',
                    'can_manage' => $event->is_owner == 1
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Managed events retrieved successfully',
                'data' => [
                    'events' => $events,
                    'total' => $events->count(),
                    'as_owner' => $events->where('my_role', 'Owner')->count(),
                    'as_panitia' => $events->where('my_role', 'Panitia')->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching managed events: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve managed events',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/events/{id}/transfer-ownership
     * 
     * Transfer ownership event ke user lain
     * ✅ Only current owner or Admin
     */
    public function transferOwnership(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'new_owner_id' => 'required|exists:user,id_user'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $event = Event::findOrFail($id);
            $currentUser = $request->user();

            // Check: Hanya owner current yang bisa transfer
            if (!$event->isOwnedBy($currentUser) && !$currentUser->hasAnyRole(['Admin'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Only current owner can transfer ownership'
                ], 403);
            }

            $newOwner = User::findOrFail($request->new_owner_id);

            // Check if new owner has EO role
            if (!$newOwner->hasAnyRole(['EO', 'Admin'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'New owner must have EO or Admin role'
                ], 400);
            }

            // Update current owner to panitia
            DB::table('user_has_event')
                ->where('id_event', $id)
                ->where('id_user', $currentUser->id_user)
                ->update(['is_owner' => 0]);

            // Check if new owner already in event
            $existing = DB::table('user_has_event')
                ->where('id_event', $id)
                ->where('id_user', $request->new_owner_id)
                ->first();

            if ($existing) {
                // Update to owner
                DB::table('user_has_event')
                    ->where('id_event', $id)
                    ->where('id_user', $request->new_owner_id)
                    ->update(['is_owner' => 1]);
            } else {
                // Add as owner
                $event->users()->attach($request->new_owner_id, [
                    'is_owner' => 1
                ]);
            }

            DB::commit();

            Log::info('Event ownership transferred', [
                'event_id' => $id,
                'old_owner_id' => $currentUser->id_user,
                'new_owner_id' => $request->new_owner_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ownership transferred successfully',
                'data' => [
                    'event_id' => $id,
                    'event_name' => $event->nama_event,
                    'old_owner' => $currentUser->nama,
                    'new_owner' => $newOwner->nama
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error transferring ownership: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to transfer ownership',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
