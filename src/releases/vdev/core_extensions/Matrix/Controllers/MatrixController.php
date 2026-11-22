<?php

namespace YTassy\Tycms\CoreExts\Matrix\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

/**
 * Squelette de la Matrix Client-Server API (C-S API), à publier à la racine
 * du domaine du homeserver. Les méthodes sont volontairement vides : les
 * attributs définissent les routes et chaque commentaire en décrit le contrat.
 *
 * CE FICHIER NE REND PAS ENCORE TYCMS COMPATIBLE AVEC ELEMENT X.
 * Element X est un client Matrix : il attend un homeserver qui stocke et signe
 * les événements, gère les rooms et leurs ACL, les tokens/appareils, /sync en
 * long-polling, les clés Olm/Megolm, le dépôt média et la fédération S-S.
 * Ce contrôleur ne représente que la surface HTTP Client-Server.
 *
 * Limites actuelles à lever avant une mise en production :
 * - ApplicationRouteRunner n'émet que JsonResponse, alors que les downloads
 *   Matrix doivent transmettre des flux binaires (médias) et le SSO rediriger ;
 * - WebRequest ne conserve pas le corps binaire des uploads media ;
 * - Router interdit deux routes de même nom : il faut donc ajouter la gestion
 *   transversale d'OPTIONS/CORS plutôt que déclarer OPTIONS pour chaque route ;
 * - les erreurs doivent respecter {"errcode":"M_*","error":"..."}.
 *
 * Les API dépréciées (events, initialSync et /_matrix/media/v3/download...) ne
 * sont pas déclarées : un client Element X actuel doit employer les remplaçants
 * v3/v1 ci-dessous. Les API Admin Synapse et Server-Server sont hors périmètre.
 */
#[Controller(name: 'Matrix', isApi: true, path: '', withoutCsrf: true)]
class MatrixController extends BaseController
{
    // Découverte : sans authentification.
    #[ControllerMethod(name: 'WellKnownClient', path: '.well-known/matrix/client', methods: [RequestMethod::GET])]
    public function wellKnownClient() { /* Retourne m.homeserver.base_url pour découvrir le homeserver depuis le Matrix ID. */ }
    #[ControllerMethod(name: 'WellKnownSupport', path: '.well-known/matrix/support', methods: [RequestMethod::GET])]
    public function wellKnownSupport() { /* Publie les contacts/support du homeserver. */ }
    #[ControllerMethod(name: 'WellKnownPolicy', path: '.well-known/matrix/policy_server', methods: [RequestMethod::GET])]
    public function wellKnownPolicy() { /* Indique le serveur de politiques, si cette fonction est proposée. */ }
    #[ControllerMethod(name: 'Versions', path: '_matrix/client/versions', methods: [RequestMethod::GET])]
    public function versions() { /* Annonce les versions C-S et fonctionnalités instables supportées. */ }

    // Authentification, sessions et compte.
    #[ControllerMethod(name: 'RegistrationTokenValidity', path: '_matrix/client/v1/register/m.login.registration_token/validity', methods: [RequestMethod::GET])]
    public function registrationTokenValidity() { /* Vérifie en query qu'un token d'inscription est utilisable. */ }
    #[ControllerMethod(name: 'LoginFlows', path: '_matrix/client/v3/login', methods: [RequestMethod::GET])]
    public function loginFlows() { /* Liste les flows de connexion : password, SSO, token, etc. */ }
    #[ControllerMethod(name: 'Login', path: '_matrix/client/v3/login', methods: [RequestMethod::POST])]
    public function login() { /* Authentifie le JSON et retourne access_token, user_id, device_id et éventuellement refresh_token. */ }
    #[ControllerMethod(name: 'LoginGetToken', path: '_matrix/client/v1/login/get_token', methods: [RequestMethod::POST])]
    public function loginGetToken() { /* Échange une assertion MSC3882 contre un token ; nécessite un service d'authentification compatible. */ }
    #[ControllerMethod(name: 'Refresh', path: '_matrix/client/v3/refresh', methods: [RequestMethod::POST])]
    public function refresh() { /* Renouvelle et fait tourner les tokens à partir d'un refresh_token. */ }
    #[ControllerMethod(name: 'Logout', path: '_matrix/client/v3/logout', methods: [RequestMethod::POST])]
    public function logout() { /* Invalide l'accès de l'appareil courant ; corps vide. */ }
    #[ControllerMethod(name: 'LogoutAll', path: '_matrix/client/v3/logout/all', methods: [RequestMethod::POST])]
    public function logoutAll() { /* Invalide toutes les sessions du compte authentifié ; corps vide. */ }
    #[ControllerMethod(name: 'Register', path: '_matrix/client/v3/register', methods: [RequestMethod::POST])]
    public function register() { /* Crée un compte avec le flux User-Interactive Authentication (UIA). */ }
    #[ControllerMethod(name: 'UsernameAvailable', path: '_matrix/client/v3/register/available', methods: [RequestMethod::GET])]
    public function usernameAvailable() { /* Indique si le localpart username demandé est disponible. */ }
    #[ControllerMethod(name: 'RegisterEmailToken', path: '_matrix/client/v3/register/email/requestToken', methods: [RequestMethod::POST])]
    public function registerEmailToken() { /* Demande le token e-mail d'inscription à l'identity server. */ }
    #[ControllerMethod(name: 'RegisterMsisdnToken', path: '_matrix/client/v3/register/msisdn/requestToken', methods: [RequestMethod::POST])]
    public function registerMsisdnToken() { /* Demande le token SMS d'inscription à l'identity server. */ }
    #[ControllerMethod(name: 'ChangePassword', path: '_matrix/client/v3/account/password', methods: [RequestMethod::POST])]
    public function changePassword() { /* Modifie le mot de passe après UIA et applique la politique de révocation de sessions. */ }
    #[ControllerMethod(name: 'PasswordEmailToken', path: '_matrix/client/v3/account/password/email/requestToken', methods: [RequestMethod::POST])]
    public function passwordEmailToken() { /* Lance la vérification e-mail du flux de mot de passe. */ }
    #[ControllerMethod(name: 'PasswordMsisdnToken', path: '_matrix/client/v3/account/password/msisdn/requestToken', methods: [RequestMethod::POST])]
    public function passwordMsisdnToken() { /* Lance la vérification SMS du flux de mot de passe. */ }
    #[ControllerMethod(name: 'DeactivateAccount', path: '_matrix/client/v3/account/deactivate', methods: [RequestMethod::POST])]
    public function deactivateAccount() { /* Désactive le compte après UIA et gère l'effacement identity server demandé. */ }
    #[ControllerMethod(name: 'AuthMetadata', path: '_matrix/client/v1/auth_metadata', methods: [RequestMethod::GET])]
    public function authMetadata() { /* Expose les métadonnées d'authentification moderne du homeserver. */ }
    #[ControllerMethod(name: 'ThreePids', path: '_matrix/client/v3/account/3pid', methods: [RequestMethod::GET])]
    public function threePids() { /* Liste e-mails et numéros vérifiés associés au compte. */ }
    #[ControllerMethod(name: 'AddThreePid', path: '_matrix/client/v3/account/3pid/add', methods: [RequestMethod::POST])]
    public function addThreePid() { /* Ajoute un 3PID dont la preuve identity server est fournie. */ }
    #[ControllerMethod(name: 'BindThreePid', path: '_matrix/client/v3/account/3pid/bind', methods: [RequestMethod::POST])]
    public function bindThreePid() { /* Lie un 3PID vérifié à l'identity server. */ }
    #[ControllerMethod(name: 'DeleteThreePid', path: '_matrix/client/v3/account/3pid/delete', methods: [RequestMethod::POST])]
    public function deleteThreePid() { /* Supprime le lien local entre le compte et un 3PID. */ }
    #[ControllerMethod(name: 'ThreePidEmailToken', path: '_matrix/client/v3/account/3pid/email/requestToken', methods: [RequestMethod::POST])]
    public function threePidEmailToken() { /* Envoie le token e-mail avant l'ajout d'un 3PID. */ }
    #[ControllerMethod(name: 'ThreePidMsisdnToken', path: '_matrix/client/v3/account/3pid/msisdn/requestToken', methods: [RequestMethod::POST])]
    public function threePidMsisdnToken() { /* Envoie le token SMS avant l'ajout d'un 3PID. */ }
    #[ControllerMethod(name: 'UnbindThreePid', path: '_matrix/client/v3/account/3pid/unbind', methods: [RequestMethod::POST])]
    public function unbindThreePid() { /* Délie un 3PID de l'identity server et signale le besoin éventuel d'une suppression locale. */ }
    #[ControllerMethod(name: 'WhoAmI', path: '_matrix/client/v3/account/whoami', methods: [RequestMethod::GET])]
    public function whoAmI() { /* Retourne user_id, device_id et is_guest du Bearer token. */ }
    #[ControllerMethod(name: 'Capabilities', path: '_matrix/client/v3/capabilities', methods: [RequestMethod::GET])]
    public function capabilities() { /* Déclare les capacités serveur : versions de room, changement de mot de passe, etc. */ }

    // Synchronisation et événements de room.
    #[ControllerMethod(name: 'CreateFilter', path: '_matrix/client/v3/user/{userId}/filter', methods: [RequestMethod::POST])]
    public function createFilter() { /* Persiste le filtre /sync JSON de userId et retourne filter_id. */ }
    #[ControllerMethod(name: 'GetFilter', path: '_matrix/client/v3/user/{userId}/filter/{filterId}', methods: [RequestMethod::GET])]
    public function getFilter() { /* Relit un filtre enregistré pour userId. */ }
    #[ControllerMethod(name: 'Sync', path: '_matrix/client/v3/sync', methods: [RequestMethod::GET])]
    public function sync() { /* Long-poll central d'Element X : traite since/timeout/filter et retourne le prochain batch de changements. */ }
    #[ControllerMethod(name: 'GetRoomEvent', path: '_matrix/client/v3/rooms/{roomId}/event/{eventId}', methods: [RequestMethod::GET])]
    public function getRoomEvent() { /* Retourne un événement visible de la room. */ }
    #[ControllerMethod(name: 'JoinedMembers', path: '_matrix/client/v3/rooms/{roomId}/joined_members', methods: [RequestMethod::GET])]
    public function joinedMembers() { /* Retourne les membres actuellement joints. */ }
    #[ControllerMethod(name: 'RoomMembers', path: '_matrix/client/v3/rooms/{roomId}/members', methods: [RequestMethod::GET])]
    public function roomMembers() { /* Paginer les événements m.room.member selon at/membership/not_membership. */ }
    #[ControllerMethod(name: 'RoomState', path: '_matrix/client/v3/rooms/{roomId}/state', methods: [RequestMethod::GET])]
    public function roomState() { /* Retourne l'état actuel visible de la room. */ }
    #[ControllerMethod(name: 'RoomStateEvent', path: '_matrix/client/v3/rooms/{roomId}/state/{eventType}/{stateKey}', methods: [RequestMethod::GET])]
    public function roomStateEvent() { /* Retourne le contenu d'un événement d'état ciblé. */ }
    #[ControllerMethod(name: 'RoomMessages', path: '_matrix/client/v3/rooms/{roomId}/messages', methods: [RequestMethod::GET])]
    public function roomMessages() { /* Paginer l'historique avec from/to/dir/limit/filter. */ }
    #[ControllerMethod(name: 'TimestampToEvent', path: '_matrix/client/v1/rooms/{roomId}/timestamp_to_event', methods: [RequestMethod::GET])]
    public function timestampToEvent() { /* Convertit un timestamp en événement d'historique proche. */ }
    #[ControllerMethod(name: 'SetRoomState', path: '_matrix/client/v3/rooms/{roomId}/state/{eventType}/{stateKey}', methods: [RequestMethod::PUT])]
    public function setRoomState() { /* Crée/remplace un état JSON après contrôle des power levels ; retourne event_id. */ }
    #[ControllerMethod(name: 'SendRoomEvent', path: '_matrix/client/v3/rooms/{roomId}/send/{eventType}/{txnId}', methods: [RequestMethod::PUT])]
    public function sendRoomEvent() { /* Persiste un événement timeline et garantit l'idempotence de txnId par appareil/endpoint. */ }
    #[ControllerMethod(name: 'RedactEvent', path: '_matrix/client/v3/rooms/{roomId}/redact/{eventId}/{txnId}', methods: [RequestMethod::PUT])]
    public function redactEvent() { /* Émet la redaction autorisée de eventId, idempotente par txnId. */ }
    #[ControllerMethod(name: 'Relations', path: '_matrix/client/v1/rooms/{roomId}/relations/{eventId}', methods: [RequestMethod::GET])]
    public function relations() { /* Paginer réponses, réactions et éditions reliées à eventId. */ }
    #[ControllerMethod(name: 'RelationsByType', path: '_matrix/client/v1/rooms/{roomId}/relations/{eventId}/{relType}', methods: [RequestMethod::GET])]
    public function relationsByType() { /* Lit les relations filtrées par relType. */ }
    #[ControllerMethod(name: 'RelationsByEventType', path: '_matrix/client/v1/rooms/{roomId}/relations/{eventId}/{relType}/{eventType}', methods: [RequestMethod::GET])]
    public function relationsByEventType() { /* Lit les relations filtrées par relation et type d'événement. */ }
    #[ControllerMethod(name: 'EventContext', path: '_matrix/client/v3/rooms/{roomId}/context/{eventId}', methods: [RequestMethod::GET])]
    public function eventContext() { /* Retourne les événements voisins et l'état nécessaire autour de eventId. */ }

    // Rooms, annuaire et membres.
    #[ControllerMethod(name: 'CreateRoom', path: '_matrix/client/v3/createRoom', methods: [RequestMethod::POST])]
    public function createRoom() { /* Crée une room depuis preset/invite/initial_state/creation_content ; retourne room_id. */ }
    #[ControllerMethod(name: 'ResolveAlias', path: '_matrix/client/v3/directory/room/{roomAlias}', methods: [RequestMethod::GET])]
    public function resolveAlias() { /* Résout roomAlias vers room_id et les serveurs candidats. */ }
    #[ControllerMethod(name: 'SetAlias', path: '_matrix/client/v3/directory/room/{roomAlias}', methods: [RequestMethod::PUT])]
    public function setAlias() { /* Associe un alias local au room_id JSON. */ }
    #[ControllerMethod(name: 'DeleteAlias', path: '_matrix/client/v3/directory/room/{roomAlias}', methods: [RequestMethod::DELETE])]
    public function deleteAlias() { /* Retire l'association d'un alias local si autorisé. */ }
    #[ControllerMethod(name: 'RoomAliases', path: '_matrix/client/v3/rooms/{roomId}/aliases', methods: [RequestMethod::GET])]
    public function roomAliases() { /* Liste les alias locaux connus de la room. */ }
    #[ControllerMethod(name: 'JoinedRooms', path: '_matrix/client/v3/joined_rooms', methods: [RequestMethod::GET])]
    public function joinedRooms() { /* Retourne les room_id joints par l'utilisateur. */ }
    #[ControllerMethod(name: 'Invite', path: '_matrix/client/v3/rooms/{roomId}/invite', methods: [RequestMethod::POST])]
    public function invite() { /* Invite un Matrix ID ou un 3PID après contrôle des power levels. */ }
    #[ControllerMethod(name: 'Join', path: '_matrix/client/v3/join/{roomIdOrAlias}', methods: [RequestMethod::POST])]
    public function join() { /* Rejoint ID/alias et utilise éventuellement server_name pour la découverte distante. */ }
    #[ControllerMethod(name: 'JoinById', path: '_matrix/client/v3/rooms/{roomId}/join', methods: [RequestMethod::POST])]
    public function joinById() { /* Variante de join par room_id avec les serveurs candidats dans le JSON. */ }
    #[ControllerMethod(name: 'Knock', path: '_matrix/client/v3/knock/{roomIdOrAlias}', methods: [RequestMethod::POST])]
    public function knock() { /* Demande l'accès à une room qui accepte les knocks. */ }
    #[ControllerMethod(name: 'Forget', path: '_matrix/client/v3/rooms/{roomId}/forget', methods: [RequestMethod::POST])]
    public function forget() { /* Oublie localement une room quittée. */ }
    #[ControllerMethod(name: 'Leave', path: '_matrix/client/v3/rooms/{roomId}/leave', methods: [RequestMethod::POST])]
    public function leave() { /* Émet le membership leave de l'utilisateur. */ }
    #[ControllerMethod(name: 'Kick', path: '_matrix/client/v3/rooms/{roomId}/kick', methods: [RequestMethod::POST])]
    public function kick() { /* Retire user_id de la room avec raison optionnelle. */ }
    #[ControllerMethod(name: 'Ban', path: '_matrix/client/v3/rooms/{roomId}/ban', methods: [RequestMethod::POST])]
    public function ban() { /* Émet un membership ban pour user_id. */ }
    #[ControllerMethod(name: 'Unban', path: '_matrix/client/v3/rooms/{roomId}/unban', methods: [RequestMethod::POST])]
    public function unban() { /* Annule le bannissement de user_id. */ }
    #[ControllerMethod(name: 'RoomDirectoryVisibility', path: '_matrix/client/v3/directory/list/room/{roomId}', methods: [RequestMethod::GET])]
    public function roomDirectoryVisibility() { /* Lit la visibilité public/private dans l'annuaire local. */ }
    #[ControllerMethod(name: 'SetRoomDirectoryVisibility', path: '_matrix/client/v3/directory/list/room/{roomId}', methods: [RequestMethod::PUT])]
    public function setRoomDirectoryVisibility() { /* Change la visibilité locale dans l'annuaire. */ }
    #[ControllerMethod(name: 'PublicRoomsGet', path: '_matrix/client/v3/publicRooms', methods: [RequestMethod::GET])]
    public function publicRoomsGet() { /* Paginer l'annuaire public selon les paramètres de query. */ }
    #[ControllerMethod(name: 'PublicRoomsPost', path: '_matrix/client/v3/publicRooms', methods: [RequestMethod::POST])]
    public function publicRoomsPost() { /* Paginer/rechercher l'annuaire avec un filtre JSON complexe. */ }
    #[ControllerMethod(name: 'RoomSummary', path: '_matrix/client/v1/room_summary/{roomIdOrAlias}', methods: [RequestMethod::GET])]
    public function roomSummary() { /* Retourne les métadonnées publiques avant de rejoindre une room. */ }
    #[ControllerMethod(name: 'UserDirectorySearch', path: '_matrix/client/v3/user_directory/search', methods: [RequestMethod::POST])]
    public function userDirectorySearch() { /* Recherche les utilisateurs selon search_term et les règles de confidentialité. */ }

    // Profil, account data, tags et spaces.
    #[ControllerMethod(name: 'GetProfile', path: '_matrix/client/v3/profile/{userId}', methods: [RequestMethod::GET])]
    public function getProfile() { /* Retourne le profil public de userId. */ }
    #[ControllerMethod(name: 'GetProfileField', path: '_matrix/client/v3/profile/{userId}/{keyName}', methods: [RequestMethod::GET])]
    public function getProfileField() { /* Retourne une propriété ciblée du profil. */ }
    #[ControllerMethod(name: 'SetProfileField', path: '_matrix/client/v3/profile/{userId}/{keyName}', methods: [RequestMethod::PUT])]
    public function setProfileField() { /* Met à jour le champ de profil de l'utilisateur authentifié. */ }
    #[ControllerMethod(name: 'DeleteProfileField', path: '_matrix/client/v3/profile/{userId}/{keyName}', methods: [RequestMethod::DELETE])]
    public function deleteProfileField() { /* Supprime un champ personnalisé du profil. */ }
    #[ControllerMethod(name: 'GetAccountData', path: '_matrix/client/v3/user/{userId}/account_data/{type}', methods: [RequestMethod::GET])]
    public function getAccountData() { /* Retourne une donnée privée globale, par exemple m.direct. */ }
    #[ControllerMethod(name: 'SetAccountData', path: '_matrix/client/v3/user/{userId}/account_data/{type}', methods: [RequestMethod::PUT])]
    public function setAccountData() { /* Enregistre une donnée privée globale incluse ensuite dans /sync. */ }
    #[ControllerMethod(name: 'GetRoomAccountData', path: '_matrix/client/v3/user/{userId}/rooms/{roomId}/account_data/{type}', methods: [RequestMethod::GET])]
    public function getRoomAccountData() { /* Retourne une donnée privée limitée à une room. */ }
    #[ControllerMethod(name: 'SetRoomAccountData', path: '_matrix/client/v3/user/{userId}/rooms/{roomId}/account_data/{type}', methods: [RequestMethod::PUT])]
    public function setRoomAccountData() { /* Enregistre une donnée privée pour une room. */ }
    #[ControllerMethod(name: 'GetRoomTags', path: '_matrix/client/v3/user/{userId}/rooms/{roomId}/tags', methods: [RequestMethod::GET])]
    public function getRoomTags() { /* Liste les tags privés : m.favourite, m.lowpriority, etc. */ }
    #[ControllerMethod(name: 'SetRoomTag', path: '_matrix/client/v3/user/{userId}/rooms/{roomId}/tags/{tag}', methods: [RequestMethod::PUT])]
    public function setRoomTag() { /* Ajoute ou met à jour un tag privé et son ordre. */ }
    #[ControllerMethod(name: 'DeleteRoomTag', path: '_matrix/client/v3/user/{userId}/rooms/{roomId}/tags/{tag}', methods: [RequestMethod::DELETE])]
    public function deleteRoomTag() { /* Retire un tag privé de room. */ }
    #[ControllerMethod(name: 'RoomHierarchy', path: '_matrix/client/v1/rooms/{roomId}/hierarchy', methods: [RequestMethod::GET])]
    public function roomHierarchy() { /* Paginer les enfants d'un Space avec max_depth et suggested_only. */ }

    // Media. Les deux uploads/downloads exigeront une extension du framework, expliquée en tête de fichier.
    #[ControllerMethod(name: 'MediaConfig', path: '_matrix/client/v1/media/config', methods: [RequestMethod::GET])]
    public function mediaConfig() { /* Retourne les limites du dépôt, notamment m.upload.size. */ }
    #[ControllerMethod(name: 'DownloadMedia', path: '_matrix/client/v1/media/download/{serverName}/{mediaId}', methods: [RequestMethod::GET])]
    public function downloadMedia() { /* Autorise puis diffuse le média binaire ou une redirection CDN. */ }
    #[ControllerMethod(name: 'DownloadMediaWithName', path: '_matrix/client/v1/media/download/{serverName}/{mediaId}/{fileName}', methods: [RequestMethod::GET])]
    public function downloadMediaWithName() { /* Même téléchargement, avec le nom de fichier demandé. */ }
    #[ControllerMethod(name: 'PreviewUrl', path: '_matrix/client/v1/media/preview_url', methods: [RequestMethod::GET])]
    public function previewUrl() { /* Produit un aperçu URL sécurisé contre SSRF ; à éviter pour les rooms chiffrées. */ }
    #[ControllerMethod(name: 'Thumbnail', path: '_matrix/client/v1/media/thumbnail/{serverName}/{mediaId}', methods: [RequestMethod::GET])]
    public function thumbnail() { /* Diffuse une miniature selon width/height/method/animated. */ }
    #[ControllerMethod(name: 'CreateMedia', path: '_matrix/media/v1/create', methods: [RequestMethod::POST])]
    public function createMedia() { /* Réserve media_id, mxc_uri et expires_at pour un upload asynchrone. */ }
    #[ControllerMethod(name: 'UploadMedia', path: '_matrix/media/v3/upload', methods: [RequestMethod::POST])]
    public function uploadMedia() { /* Reçoit un corps binaire brut et retourne content_uri. */ }
    #[ControllerMethod(name: 'UploadReservedMedia', path: '_matrix/media/v3/upload/{serverName}/{mediaId}', methods: [RequestMethod::PUT])]
    public function uploadReservedMedia() { /* Envoie le corps binaire vers une réservation /create. */ }

    // Appareils et E2EE : nécessaires aux rooms chiffrées d'Element X.
    #[ControllerMethod(name: 'DeleteDevices', path: '_matrix/client/v3/delete_devices', methods: [RequestMethod::POST])]
    public function deleteDevices() { /* Supprime plusieurs appareils après UIA. */ }
    #[ControllerMethod(name: 'Devices', path: '_matrix/client/v3/devices', methods: [RequestMethod::GET])]
    public function devices() { /* Liste les appareils et leurs métadonnées. */ }
    #[ControllerMethod(name: 'GetDevice', path: '_matrix/client/v3/devices/{deviceId}', methods: [RequestMethod::GET])]
    public function getDevice() { /* Retourne un appareil appartenant à l'utilisateur. */ }
    #[ControllerMethod(name: 'UpdateDevice', path: '_matrix/client/v3/devices/{deviceId}', methods: [RequestMethod::PUT])]
    public function updateDevice() { /* Modifie display_name d'un appareil. */ }
    #[ControllerMethod(name: 'DeleteDevice', path: '_matrix/client/v3/devices/{deviceId}', methods: [RequestMethod::DELETE])]
    public function deleteDevice() { /* Supprime un appareil après UIA et révoque son accès. */ }
    #[ControllerMethod(name: 'UploadDeviceSigning', path: '_matrix/client/v3/keys/device_signing/upload', methods: [RequestMethod::POST])]
    public function uploadDeviceSigning() { /* Enregistre les clés master, self-signing et user-signing après UIA. */ }
    #[ControllerMethod(name: 'UploadSignatures', path: '_matrix/client/v3/keys/signatures/upload', methods: [RequestMethod::POST])]
    public function uploadSignatures() { /* Publie les signatures appliquées aux clés d'utilisateurs/appareils. */ }
    #[ControllerMethod(name: 'GetRoomKeys', path: '_matrix/client/v3/room_keys/keys', methods: [RequestMethod::GET])]
    public function getRoomKeys() { /* Exporte les clés de la sauvegarde E2EE. */ }
    #[ControllerMethod(name: 'PutRoomKeys', path: '_matrix/client/v3/room_keys/keys', methods: [RequestMethod::PUT])]
    public function putRoomKeys() { /* Charge en lot des clés dans une sauvegarde. */ }
    #[ControllerMethod(name: 'DeleteRoomKeys', path: '_matrix/client/v3/room_keys/keys', methods: [RequestMethod::DELETE])]
    public function deleteRoomKeys() { /* Efface toutes les clés d'une sauvegarde. */ }
    #[ControllerMethod(name: 'GetRoomKeysForRoom', path: '_matrix/client/v3/room_keys/keys/{roomId}', methods: [RequestMethod::GET])]
    public function getRoomKeysForRoom() { /* Exporte les clés sauvegardées d'une room. */ }
    #[ControllerMethod(name: 'PutRoomKeysForRoom', path: '_matrix/client/v3/room_keys/keys/{roomId}', methods: [RequestMethod::PUT])]
    public function putRoomKeysForRoom() { /* Charge les clés sauvegardées d'une room. */ }
    #[ControllerMethod(name: 'DeleteRoomKeysForRoom', path: '_matrix/client/v3/room_keys/keys/{roomId}', methods: [RequestMethod::DELETE])]
    public function deleteRoomKeysForRoom() { /* Efface les clés sauvegardées d'une room. */ }
    #[ControllerMethod(name: 'GetRoomKeysForSession', path: '_matrix/client/v3/room_keys/keys/{roomId}/{sessionId}', methods: [RequestMethod::GET])]
    public function getRoomKeysForSession() { /* Exporte une clé de session Megolm. */ }
    #[ControllerMethod(name: 'PutRoomKeysForSession', path: '_matrix/client/v3/room_keys/keys/{roomId}/{sessionId}', methods: [RequestMethod::PUT])]
    public function putRoomKeysForSession() { /* Charge une clé de session Megolm. */ }
    #[ControllerMethod(name: 'DeleteRoomKeysForSession', path: '_matrix/client/v3/room_keys/keys/{roomId}/{sessionId}', methods: [RequestMethod::DELETE])]
    public function deleteRoomKeysForSession() { /* Efface une clé de session Megolm. */ }
    #[ControllerMethod(name: 'GetBackupVersion', path: '_matrix/client/v3/room_keys/version', methods: [RequestMethod::GET])]
    public function getBackupVersion() { /* Retourne la version active de sauvegarde de clés. */ }
    #[ControllerMethod(name: 'CreateBackupVersion', path: '_matrix/client/v3/room_keys/version', methods: [RequestMethod::POST])]
    public function createBackupVersion() { /* Crée une version de sauvegarde et retourne son identifiant. */ }
    #[ControllerMethod(name: 'GetBackupVersionById', path: '_matrix/client/v3/room_keys/version/{version}', methods: [RequestMethod::GET])]
    public function getBackupVersionById() { /* Retourne les métadonnées de la version demandée. */ }
    #[ControllerMethod(name: 'UpdateBackupVersion', path: '_matrix/client/v3/room_keys/version/{version}', methods: [RequestMethod::PUT])]
    public function updateBackupVersion() { /* Met à jour les métadonnées de la sauvegarde. */ }
    #[ControllerMethod(name: 'DeleteBackupVersion', path: '_matrix/client/v3/room_keys/version/{version}', methods: [RequestMethod::DELETE])]
    public function deleteBackupVersion() { /* Supprime une version et toutes ses clés. */ }
    #[ControllerMethod(name: 'KeyChanges', path: '_matrix/client/v3/keys/changes', methods: [RequestMethod::GET])]
    public function keyChanges() { /* Retourne les utilisateurs dont les clés ont changé entre from et to. */ }
    #[ControllerMethod(name: 'ClaimKeys', path: '_matrix/client/v3/keys/claim', methods: [RequestMethod::POST])]
    public function claimKeys() { /* Réserve les one-time keys nécessaires à une session Olm. */ }
    #[ControllerMethod(name: 'QueryKeys', path: '_matrix/client/v3/keys/query', methods: [RequestMethod::POST])]
    public function queryKeys() { /* Retourne device_keys, master_keys et signatures demandées. */ }
    #[ControllerMethod(name: 'UploadKeys', path: '_matrix/client/v3/keys/upload', methods: [RequestMethod::POST])]
    public function uploadKeys() { /* Enregistre les clés de l'appareil, one-time et fallback. */ }
    #[ControllerMethod(name: 'SendToDevice', path: '_matrix/client/v3/sendToDevice/{eventType}/{txnId}', methods: [RequestMethod::PUT])]
    public function sendToDevice() { /* Distribue un événement ciblé par appareil, idempotent par txnId. */ }

    // Présence, notifications, lecture et fonctions complémentaires.
    #[ControllerMethod(name: 'GetPresence', path: '_matrix/client/v3/presence/{userId}/status', methods: [RequestMethod::GET])]
    public function getPresence() { /* Retourne la présence visible de userId. */ }
    #[ControllerMethod(name: 'SetPresence', path: '_matrix/client/v3/presence/{userId}/status', methods: [RequestMethod::PUT])]
    public function setPresence() { /* Modifie la présence de l'utilisateur authentifié. */ }
    #[ControllerMethod(name: 'PushRules', path: '_matrix/client/v3/pushrules/', methods: [RequestMethod::GET])]
    public function pushRules() { /* Retourne toutes les règles de notification. */ }
    #[ControllerMethod(name: 'GlobalPushRules', path: '_matrix/client/v3/pushrules/global/', methods: [RequestMethod::GET])]
    public function globalPushRules() { /* Retourne les règles globales par kind. */ }
    #[ControllerMethod(name: 'PushRule', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}', methods: [RequestMethod::GET])]
    public function pushRule() { /* Retourne une règle de notification précise. */ }
    #[ControllerMethod(name: 'SetPushRule', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}', methods: [RequestMethod::PUT])]
    public function setPushRule() { /* Crée ou met à jour une règle et ses conditions/actions. */ }
    #[ControllerMethod(name: 'DeletePushRule', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}', methods: [RequestMethod::DELETE])]
    public function deletePushRule() { /* Supprime une règle de notification personnalisée. */ }
    #[ControllerMethod(name: 'PushRuleActions', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}/actions', methods: [RequestMethod::GET])]
    public function pushRuleActions() { /* Retourne les actions notify/highlight/dont_notify d'une règle. */ }
    #[ControllerMethod(name: 'SetPushRuleActions', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}/actions', methods: [RequestMethod::PUT])]
    public function setPushRuleActions() { /* Remplace les actions d'une règle. */ }
    #[ControllerMethod(name: 'PushRuleEnabled', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}/enabled', methods: [RequestMethod::GET])]
    public function pushRuleEnabled() { /* Indique si une règle est active. */ }
    #[ControllerMethod(name: 'SetPushRuleEnabled', path: '_matrix/client/v3/pushrules/global/{kind}/{ruleId}/enabled', methods: [RequestMethod::PUT])]
    public function setPushRuleEnabled() { /* Active ou désactive une règle sans la modifier. */ }
    #[ControllerMethod(name: 'Pushers', path: '_matrix/client/v3/pushers', methods: [RequestMethod::GET])]
    public function pushers() { /* Liste les destinations de push du compte. */ }
    #[ControllerMethod(name: 'SetPusher', path: '_matrix/client/v3/pushers/set', methods: [RequestMethod::POST])]
    public function setPusher() { /* Ajoute, modifie ou retire un pusher ; l'envoi requiert un Push Gateway. */ }
    #[ControllerMethod(name: 'Notifications', path: '_matrix/client/v3/notifications', methods: [RequestMethod::GET])]
    public function notifications() { /* Paginer les notifications persistées. */ }
    #[ControllerMethod(name: 'ReadMarkers', path: '_matrix/client/v3/rooms/{roomId}/read_markers', methods: [RequestMethod::POST])]
    public function readMarkers() { /* Enregistre m.fully_read et m.read_private et les reflète dans /sync. */ }
    #[ControllerMethod(name: 'Receipt', path: '_matrix/client/v3/rooms/{roomId}/receipt/{receiptType}/{eventId}', methods: [RequestMethod::POST])]
    public function receipt() { /* Publie un reçu m.read ou m.read.private. */ }
    #[ControllerMethod(name: 'ReportRoom', path: '_matrix/client/v3/rooms/{roomId}/report', methods: [RequestMethod::POST])]
    public function reportRoom() { /* Signale une room avec reason et score. */ }
    #[ControllerMethod(name: 'ReportEvent', path: '_matrix/client/v3/rooms/{roomId}/report/{eventId}', methods: [RequestMethod::POST])]
    public function reportEvent() { /* Signale un événement avec reason et score. */ }
    #[ControllerMethod(name: 'ReportUser', path: '_matrix/client/v3/users/{userId}/report', methods: [RequestMethod::POST])]
    public function reportUser() { /* Signale un utilisateur avec reason et score. */ }
    #[ControllerMethod(name: 'UpgradeRoom', path: '_matrix/client/v3/rooms/{roomId}/upgrade', methods: [RequestMethod::POST])]
    public function upgradeRoom() { /* Crée la room de version cible et lie tombstone/predecessor. */ }
    #[ControllerMethod(name: 'Search', path: '_matrix/client/v3/search', methods: [RequestMethod::POST])]
    public function search() { /* Exécute la recherche full-text sur les événements accessibles. */ }
    #[ControllerMethod(name: 'OpenIdToken', path: '_matrix/client/v3/user/{userId}/openid/request_token', methods: [RequestMethod::POST])]
    public function openIdToken() { /* Émet un token OpenID court pour userId authentifié. */ }
    #[ControllerMethod(name: 'MutualRooms', path: '_matrix/client/v1/mutual_rooms', methods: [RequestMethod::GET])]
    public function mutualRooms() { /* Liste les rooms mutuelles avec user_id selon la confidentialité. */ }
    #[ControllerMethod(name: 'RoomPreview', path: '_matrix/client/v3/events', methods: [RequestMethod::GET])]
    public function roomPreview() { /* Retourne l'aperçu public d'événements d'une room sans la rejoindre. */ }
    #[ControllerMethod(name: 'SsoRedirect', path: '_matrix/client/v3/login/sso/redirect', methods: [RequestMethod::GET])]
    public function ssoRedirect() { /* Démarre le SSO par défaut puis redirige vers redirectUrl. */ }
    #[ControllerMethod(name: 'SsoRedirectIdp', path: '_matrix/client/v3/login/sso/redirect/{idpId}', methods: [RequestMethod::GET])]
    public function ssoRedirectIdp() { /* Même flux SSO, mais pour idpId. */ }
    #[ControllerMethod(name: 'ThirdPartyLocation', path: '_matrix/client/v3/thirdparty/location', methods: [RequestMethod::GET])]
    public function thirdPartyLocation() { /* Recherche les rooms des réseaux tiers. */ }
    #[ControllerMethod(name: 'ThirdPartyLocationProtocol', path: '_matrix/client/v3/thirdparty/location/{protocol}', methods: [RequestMethod::GET])]
    public function thirdPartyLocationProtocol() { /* Recherche les rooms d'un protocole tiers donné. */ }
    #[ControllerMethod(name: 'ThirdPartyProtocol', path: '_matrix/client/v3/thirdparty/protocol/{protocol}', methods: [RequestMethod::GET])]
    public function thirdPartyProtocol() { /* Décrit un protocole tiers et ses instances. */ }
    #[ControllerMethod(name: 'ThirdPartyProtocols', path: '_matrix/client/v3/thirdparty/protocols', methods: [RequestMethod::GET])]
    public function thirdPartyProtocols() { /* Liste les protocoles tiers exposés par les application services. */ }
    #[ControllerMethod(name: 'ThirdPartyUser', path: '_matrix/client/v3/thirdparty/user', methods: [RequestMethod::GET])]
    public function thirdPartyUser() { /* Recherche les utilisateurs des réseaux tiers. */ }
    #[ControllerMethod(name: 'ThirdPartyUserProtocol', path: '_matrix/client/v3/thirdparty/user/{protocol}', methods: [RequestMethod::GET])]
    public function thirdPartyUserProtocol() { /* Recherche les utilisateurs d'un protocole tiers donné. */ }
    #[ControllerMethod(name: 'Threads', path: '_matrix/client/v1/rooms/{roomId}/threads', methods: [RequestMethod::GET])]
    public function threads() { /* Paginer les fils de discussion de la room. */ }
    #[ControllerMethod(name: 'Typing', path: '_matrix/client/v3/rooms/{roomId}/typing/{userId}', methods: [RequestMethod::PUT])]
    public function typing() { /* Publie ou arrête l'indicateur de frappe pour timeout millisecondes. */ }
    #[ControllerMethod(name: 'TurnServer', path: '_matrix/client/v3/voip/turnServer', methods: [RequestMethod::GET])]
    public function turnServer() { /* Retourne les identifiants TURN/STUN temporaires pour WebRTC. */ }
}
