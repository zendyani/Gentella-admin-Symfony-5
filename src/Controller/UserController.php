<?php


namespace App\Controller;


use App\Entity\Role;
use App\Entity\User;
use App\Form\ChangePwsdFormType;
use App\Form\UserFormType;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\Adapter\ArrayAdapter;
use Omines\DataTablesBundle\Column\TextColumn;
use Omines\DataTablesBundle\Column\TwigStringColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableFactory;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserController extends BaseController
{
    private $userRepository;
    private $passwordEncoder;

    private $entityManager;
    private $roleRepository;

    public function __construct(UserRepository $userRepository, RoleRepository $roleRepository, UserPasswordEncoderInterface $passwordEncoder, EntityManagerInterface $entityManager)
    {
        $this->userRepository = $userRepository;
        $this->passwordEncoder = $passwordEncoder;
        $this->entityManager = $entityManager;
        $this->roleRepository = $roleRepository;
    }

    public function fakepswd(Request $request)
    {
        $this->denyAccessUnlessGranted("ROLE_ADMIN");
        $user = new User();
        $user->setValid(true)
            ->setDeleted(false)
            ->setEmail("mam@ddd.com")
            ->setNomComplet("nom comp")
            ->setUsername("mamless")
            ->setRoles(["ROLE_ADMIN"])
            ->setPassword($this->passwordEncoder->encodePassword($user, $request->get("password")));
        // $user = $this->userRepository->saveUser($user);
        return $this->json(["id" => $user->getId(), "password" => $user->getPassword(), "decode" => $this->passwordEncoder->isPasswordValid($user, 1)]);
    }

    /**
     * @Route("/admin/user",name="app_admin_users")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function users()
    {
        $users = $this->userRepository->findBy([], ['username' => 'ASC']);
        // dd($users);
        return $this->render("admin/user/user.html.twig", ["users" => $users]);
    }

    /**
     * @Route("/admin/user-json",name="app_admin_users_json")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function usersJson(Request $req, SerializerInterface $serializer)
    {
        $users = $this->userRepository->findBy([], ['username' => 'ASC']);
        $data = $serializer->serialize($users, 'json', ['groups' => ['listing']]);
        // dd(JsonResponse::fromJsonString($data));
        // dd($req->request->get('columns'));
        return JsonResponse::fromJsonString($data);
    }

    /**
     * @Route("/admin/user-table",name="app_admin_users_table")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function usersTable(Request $request, DataTableFactory $dataTableFactory)
    {
        $table = $dataTableFactory->create()
            ->add('selection', TextColumn::class, ['label' => '
                <input type="checkbox" name="selectAll" id="selectAll">
            '])
            ->add('username', TextColumn::class, ['orderable' => true])
            ->add('email', TextColumn::class, ['orderable' => true, 'render' => function ($value, $context) {
                return sprintf('<a href="%s">%s</a>', $value, $value);
            }])
            ->add('fullname', TextColumn::class, ['orderable' => true])
            ->add('role', TextColumn::class, ['orderable' => true])

            ->add('edit', TwigStringColumn::class, [
                'template' => '<a class="btn btn-primary" href="{{ url(\'app_admin_users_table\', {id: row.email}) }}"><i class="fa fa-edit"></i></a>',
            ])
            ->add('status', TwigStringColumn::class, [
                'template' => '<a class="btn btn-success activate-link" href="{{ url(\'app_admin_users_table\', {id: row.email}) }}"><i class="fa fa-check"></i></a>',
            ])
            ->add('delete', TwigStringColumn::class, [
                'template' => '<a class="btn btn-danger" href="{{ url(\'app_admin_users_table\', {id: row.email}) }}"><i class="fa fa-trash"></i></a>',
            ])

            ->createAdapter(ArrayAdapter::class, [
                ['username' => 'Donald', 'email' => 'Trump@oba.com', 'fullname' => 'Trump is baka', 'role' => 'Admin'],
                ['username' => 'Barack', 'email' => 'Obama@tru.com', 'fullname' => 'Trump is cool', 'role' => 'Super Admin'],
            ])
            ->handleRequest($request);

        if ($table->isCallback()) {
            return $table->getResponse();
        }

        $users = $this->userRepository->findAll();
        return $this->render("admin/user/user_table.html.twig", ["users" => $users, 'datatable' => $table]);
    }

    /**
     * @Route("/admin/user/new",name="app_admin_new_user")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function newUser(Request $request, TranslatorInterface $translator)
    {
        $form = $this->createForm(UserFormType::class, null, ["translator" => $translator]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var  User $user */
            $user = $form->getData();
            /** @var Role $role */
            $password = $form["justpassword"]->getData();
            $role = $form["role"]->getData();
            $user->setValid(true)
                ->setDeleted(false)
                ->setAdmin(true)
                ->setPassword($this->passwordEncoder->encodePassword($user, $password))
                ->setRoles([$role->getRoleName()]);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash("success", $translator->trans('backend.user.add_user'));
            return $this->redirectToRoute("app_admin_users");
        }
        return $this->render("admin/user/userform.html.twig", ["userForm" => $form->createView()]);
    }

    /**
     * @Route("/admin/user/edit/{id}",name="app_admin_edit_user")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function editUser(User $user, Request $request, TranslatorInterface $translator)
    {
        $form = $this->createForm(UserFormType::class, $user, ["translator" => $translator]);
        $form->get('justpassword')->setData($user->getPassword());
        $therole = $this->roleRepository->findOneBy(["roleName" => $user->getRoles()[0]]);
        $form->get('role')->setData($therole);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Role $role */
            $role = $form["role"]->getData();
            $password = $form["justpassword"]->getData();
            $user->setRoles([$role->getRoleName()]);
            if ($user->getPassword() != $password) {
                $user->setPassword($this->passwordEncoder->encodePassword($user, $password));
            }
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash("success", $translator->trans('backend.user.modify_user'));
            return $this->redirectToRoute("app_admin_users");
        }
        return $this->render("admin/user/userform.html.twig", ["userForm" => $form->createView()]);
    }

    /**
     * @Route("/admin/user/changevalidite/{id}",name="app_admin_changevalidite_user",methods={"post"})
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function activate(User $user)
    {
        $user = $this->userRepository->changeValidite($user);
        return $this->json(["message" => "success", "value" => $user->isValid()]);
    }

    /**
     * @Route("/admin/user/delete/{id}",name="app_admin_delete_user")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function delete(User $user)
    {
        $user = $this->userRepository->delete($user);
        /*$this->addFlash("success","Utilisateur supprimé");
        return $this->redirectToRoute('app_admin_users');*/
        return $this->json(["message" => "success", "value" => $user->isDeleted()]);
    }

    /**
     * @Route("/admin/user/changePassword",name="app_admin_changepswd")
     * @IsGranted("ROLE_USER")
     */
    public function changePswd(Request $request, TranslatorInterface $translator)
    {
        $user = $this->getUser();
        $form = $this->createForm(ChangePwsdFormType::class, $user, ["translator" => $translator]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $password =  $form["justpassword"]->getData();
            $newPassword = $form["newpassword"]->getData();

            if ($this->passwordEncoder->isPasswordValid($user, $password)) {
                $user->setPassword($this->passwordEncoder->encodePassword($user, $newPassword));
            } else {
                $this->addFlash("error", $translator->trans('backend.user.new_passwod_must_be'));
                return $this->render("admin/params/changeMdpForm.html.twig", ["passwordForm" => $form->createView()]);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash("success", $translator->trans('backend.user.changed_password'));
            return $this->redirectToRoute("app_admin_index");
        }
        return $this->render("admin/params/changeMdpForm.html.twig", ["passwordForm" => $form->createView()]);
    }

    /**
     * @Route("/admin/user/groupaction",name="app_admin_groupaction_user")
     * @IsGranted("ROLE_SUPERUSER")
     */
    public function groupAction(Request $request, TranslatorInterface $translator)
    {
        $action = $request->get("action");
        $ids = $request->get("ids");
        $users = $this->userRepository->findBy(["id" => $ids]);

        if ($action == $translator->trans('backend.user.deactivate')) {
            foreach ($users as $user) {
                $user->setValid(false);
                $this->entityManager->persist($user);
            }
        } else if ($action == $translator->trans('backend.user.Activate')) {
            foreach ($users as $user) {
                $user->setValid(true);
                $this->entityManager->persist($user);
            }
        } else if ($action == $translator->trans('backend.user.delete')) {
            foreach ($users as $user) {
                $user->setDeleted(true);
                $this->entityManager->persist($user);
            }
        } else {
            return $this->json(["message" => "error"]);
        }
        $this->entityManager->flush();
        return $this->json(["message" => "success", "nb" => count($users)]);
    }
}
