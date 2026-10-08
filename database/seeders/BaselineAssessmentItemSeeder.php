<?php

namespace Database\Seeders;

use App\Models\BaselineAssessmentItem;
use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Prototype baseline question bank.
 *
 * Questions are attached to SKILLS, never directly to career roles: the
 * existing BaselineQuestionSelectionService resolves
 *
 *   career role -> required skills -> questions mapped to those skills
 *
 * so a question only has to be authored once per skill and every role that
 * requires that skill inherits it. This is what makes the assessment
 * "dynamic" — changing the career role (which is itself filtered by
 * specialization) changes the skill set and therefore the questions.
 *
 * Every skill referenced by CareerRoleSeeder has at least one active item
 * here, otherwise selection would raise INSUFFICIENT_QUESTION_COVERAGE for
 * a role whose skill has no question. The keys are the skill slugs produced
 * by SkillSeeder (Str::slug of the skill name) — e.g. "TCP/IP" -> "tcpip",
 * "C/C++" -> "cc".
 *
 * Item ids are "{skillSlug}-001"; updateOrCreate on (assessment_version,
 * item_id) keeps the seed idempotent. Options are the answer choices and
 * correct_answer is one of them — the learner-facing API never exposes
 * correct_answer (see BaselineAssessmentController::transformQuestion).
 */
class BaselineAssessmentItemSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            // ---- Backend ----
            'programming-fundamentals' => [
                'What is the main purpose of a variable in a program?',
                [
                    'To store a value that can be reused and changed',
                    'To permanently delete data from memory',
                    'To define the visual style of the application',
                    'To establish a network connection',
                ],
                'To store a value that can be reused and changed',
            ],
            'sql-databases' => [
                'What is the purpose of a primary key in a database table?',
                [
                    'To uniquely identify each record in the table',
                    'To sort records alphabetically',
                    'To allow duplicate rows',
                    'To store the largest value in the table',
                ],
                'To uniquely identify each record in the table',
            ],
            'rest-apis' => [
                'What is the main difference between the GET and POST HTTP methods?',
                [
                    'GET retrieves data, while POST is commonly used to submit or create data',
                    'GET creates data, while POST only reads data',
                    'Both methods behave identically',
                    'GET is only used for authentication',
                ],
                'GET retrieves data, while POST is commonly used to submit or create data',
            ],
            'authentication-authorization' => [
                'What is the difference between authentication and authorization?',
                [
                    'Authentication verifies identity, while authorization determines permissions',
                    'They are two names for the same process',
                    'Authentication grants permissions, while authorization verifies identity',
                    'Both only apply to databases',
                ],
                'Authentication verifies identity, while authorization determines permissions',
            ],
            'backend-architecture' => [
                'What is the role of middleware in a backend application?',
                [
                    'It processes requests between the client and the application logic',
                    'It stores the static images of the application',
                    'It replaces the database',
                    'It renders the user interface',
                ],
                'It processes requests between the client and the application logic',
            ],

            // ---- Data & Analytics ----
            'statistics-fundamentals' => [
                'What is the difference between the mean and the median?',
                [
                    'The mean is the average, while the median is the middle value',
                    'The median is the average, while the mean is the middle value',
                    'They are always identical',
                    'The mean only applies to text data',
                ],
                'The mean is the average, while the median is the middle value',
            ],
            'sql' => [
                'What does the SQL GROUP BY clause do?',
                [
                    'It groups rows that share a value so aggregates can be computed',
                    'It deletes duplicate tables',
                    'It renames a column',
                    'It connects to a remote server',
                ],
                'It groups rows that share a value so aggregates can be computed',
            ],
            'data-cleaning' => [
                'Why is data cleaning important before analysis?',
                [
                    'It removes errors and inconsistencies that would distort results',
                    'It makes the dataset smaller by deleting all rows',
                    'It encrypts the data',
                    'It is only required for text data',
                ],
                'It removes errors and inconsistencies that would distort results',
            ],
            'data-visualization' => [
                'Why is data visualization useful?',
                [
                    'It makes patterns and trends easier to understand',
                    'It permanently stores raw data',
                    'It replaces the need for data cleaning',
                    'It guarantees that the data is correct',
                ],
                'It makes patterns and trends easier to understand',
            ],
            'data-analysis' => [
                'What is the difference between correlation and causation?',
                [
                    'Correlation shows a relationship, while causation means one thing causes another',
                    'They mean exactly the same thing',
                    'Causation is only about numbers',
                    'Correlation proves causation',
                ],
                'Correlation shows a relationship, while causation means one thing causes another',
            ],

            // ---- Machine Learning & AI ----
            'python' => [
                'Why is Python commonly used in AI and data work?',
                [
                    'It has a rich ecosystem of data and machine-learning libraries',
                    'It is the only language that supports loops',
                    'It cannot be used for scripting',
                    'It compiles directly to machine code only',
                ],
                'It has a rich ecosystem of data and machine-learning libraries',
            ],
            'machine-learning' => [
                'What is the difference between supervised and unsupervised learning?',
                [
                    'Supervised learning uses labelled data, while unsupervised learning does not',
                    'Unsupervised learning always uses labels',
                    'Supervised learning never uses data',
                    'They are the same technique',
                ],
                'Supervised learning uses labelled data, while unsupervised learning does not',
            ],
            'neural-networks' => [
                'What is a neural network?',
                [
                    'A set of connected layers of nodes that learn patterns from data',
                    'A database of labelled records',
                    'A type of network cable',
                    'A programming language',
                ],
                'A set of connected layers of nodes that learn patterns from data',
            ],
            'model-evaluation' => [
                'What is overfitting?',
                [
                    'When a model performs well on training data but poorly on new data',
                    'When a model is too simple to learn anything',
                    'When the dataset is empty',
                    'When training is stopped too early',
                ],
                'When a model performs well on training data but poorly on new data',
            ],
            'ai-fundamentals' => [
                'What is the difference between Artificial Intelligence and Machine Learning?',
                [
                    'AI is the broader goal, while Machine Learning is one approach to achieve it',
                    'Machine Learning is broader than AI',
                    'They are unrelated fields',
                    'AI only refers to hardware',
                ],
                'AI is the broader goal, while Machine Learning is one approach to achieve it',
            ],
            'statistics' => [
                'What is an outlier in a dataset?',
                [
                    'A value that differs significantly from the other observations',
                    'The average of all values',
                    'The most frequent value',
                    'The smallest possible number',
                ],
                'A value that differs significantly from the other observations',
            ],
            'feature-engineering' => [
                'What is feature engineering?',
                [
                    'Creating and selecting input variables that improve a model',
                    'Writing the user interface',
                    'Deploying the model to production',
                    'Labelling the dataset',
                ],
                'Creating and selecting input variables that improve a model',
            ],
            'model-training' => [
                'What is the difference between model training and inference?',
                [
                    'Training learns parameters from data, while inference uses the trained model to make predictions',
                    'Inference learns the parameters from data',
                    'They are the same step',
                    'Training only applies to databases',
                ],
                'Training learns parameters from data, while inference uses the trained model to make predictions',
            ],
            'model-deployment' => [
                'What is model deployment?',
                [
                    'Making a trained model available for use in production',
                    'Labelling the training data',
                    'Choosing the number of layers',
                    'Cleaning the dataset',
                ],
                'Making a trained model available for use in production',
            ],
            'model-monitoring' => [
                'Why should a deployed machine-learning model be monitored?',
                [
                    'To detect performance degradation and data drift over time',
                    'To make it train faster',
                    'To reduce the dataset size',
                    'To change the programming language',
                ],
                'To detect performance degradation and data drift over time',
            ],
            'natural-language-processing' => [
                'What is Natural Language Processing?',
                [
                    'A field of AI that enables computers to understand human language',
                    'A database engine',
                    'A network protocol',
                    'A frontend framework',
                ],
                'A field of AI that enables computers to understand human language',
            ],
            'tokenization' => [
                'What is tokenization in Natural Language Processing?',
                [
                    'Splitting text into smaller units such as words or subwords',
                    'Encrypting text for security',
                    'Compressing images',
                    'Sorting a database',
                ],
                'Splitting text into smaller units such as words or subwords',
            ],
            'word-embeddings' => [
                'What is a word embedding?',
                [
                    'A dense numeric vector that represents the meaning of a word',
                    'A type of database index',
                    'A network address',
                    'A CSS rule',
                ],
                'A dense numeric vector that represents the meaning of a word',
            ],
            'tf-idf' => [
                'What does TF-IDF measure?',
                [
                    'How important a word is to a document relative to a collection of documents',
                    'The speed of a network',
                    'The size of a database',
                    'The colour of a web page',
                ],
                'How important a word is to a document relative to a collection of documents',
            ],
            'text-classification' => [
                'What is sentiment analysis an example of?',
                [
                    'Text classification',
                    'Image segmentation',
                    'Database indexing',
                    'Network routing',
                ],
                'Text classification',
            ],

            // ---- Frontend ----
            'html' => [
                'What is the main purpose of HTML?',
                [
                    'To define the structure and content of a web page',
                    'To style a web page',
                    'To store data in a database',
                    'To run server-side logic',
                ],
                'To define the structure and content of a web page',
            ],
            'css' => [
                'What is the main purpose of CSS?',
                [
                    'To control the presentation and layout of a web page',
                    'To define the data model of a page',
                    'To handle HTTP requests',
                    'To store user credentials',
                ],
                'To control the presentation and layout of a web page',
            ],
            'javascript' => [
                'What is the difference between let and const in JavaScript?',
                [
                    'let allows reassignment, while const does not',
                    'const allows reassignment, while let does not',
                    'They are identical',
                    'Both are only used for styling',
                ],
                'let allows reassignment, while const does not',
            ],
            'responsive-design' => [
                'What does responsive web design mean?',
                [
                    'The layout adapts to different screen sizes and devices',
                    'The page reloads on every click',
                    'The site only works on desktops',
                    'The page uses only images',
                ],
                'The layout adapts to different screen sizes and devices',
            ],
            'frontend-frameworks' => [
                'What is a component in a frontend framework such as React?',
                [
                    'A reusable, self-contained piece of the user interface',
                    'A database table',
                    'A network protocol',
                    'A type of CSS file',
                ],
                'A reusable, self-contained piece of the user interface',
            ],

            // ---- Mobile ----
            'android-development' => [
                'What is the purpose of an Activity in Android?',
                [
                    'It represents a single screen with a user interface',
                    'It stores the application database',
                    'It defines the application icon',
                    'It manages the network hardware of the device',
                ],
                'It represents a single screen with a user interface',
            ],
            'application-lifecycle' => [
                'What does the Android application lifecycle describe?',
                [
                    'The states an application moves through from launch to termination',
                    'The order of database migrations',
                    'The process of uploading to an app store',
                    'The design of the application icon',
                ],
                'The states an application moves through from launch to termination',
            ],
            'ui-components' => [
                'What is RecyclerView used for in Android?',
                [
                    'To efficiently display scrollable lists of items',
                    'To store data locally',
                    'To send HTTP requests',
                    'To define the application theme',
                ],
                'To efficiently display scrollable lists of items',
            ],
            'rest-api-integration' => [
                'Why would a mobile application use a REST API?',
                [
                    'To exchange data with a remote server',
                    'To render HTML on the server',
                    'To compile the application',
                    'To store files on the device only',
                ],
                'To exchange data with a remote server',
            ],
            'local-data-storage' => [
                'Why is local data storage useful in a mobile application?',
                [
                    'It lets the application keep data available offline',
                    'It replaces the need for a backend',
                    'It increases the screen resolution',
                    'It removes the need for user login',
                ],
                'It lets the application keep data available offline',
            ],

            // ---- IT & Operating Systems ----
            'computer-hardware' => [
                'What is the difference between RAM and storage?',
                [
                    'RAM is volatile working memory, while storage keeps data persistently',
                    'Storage is volatile, while RAM is persistent',
                    'They are the same component',
                    'RAM is used only for networking',
                ],
                'RAM is volatile working memory, while storage keeps data persistently',
            ],
            'operating-systems' => [
                'What is the main purpose of an operating system?',
                [
                    'To manage hardware and provide services for applications',
                    'To browse the internet',
                    'To design web pages',
                    'To store backups only',
                ],
                'To manage hardware and provide services for applications',
            ],
            'servers' => [
                'What is a server?',
                [
                    'A computer that provides services or resources to other computers',
                    'A type of printer',
                    'A mobile application',
                    'A database row',
                ],
                'A computer that provides services or resources to other computers',
            ],
            'user-permission-management' => [
                'What is the difference between a normal user and an administrator?',
                [
                    'An administrator has elevated privileges to manage the system',
                    'A normal user can change any system setting',
                    'They have identical permissions',
                    'Administrators cannot log in',
                ],
                'An administrator has elevated privileges to manage the system',
            ],
            'backup-recovery' => [
                'Why are backups important?',
                [
                    'They allow data to be restored after loss or failure',
                    'They make the system run faster',
                    'They replace antivirus software',
                    'They are only needed once',
                ],
                'They allow data to be restored after loss or failure',
            ],
            'system-monitoring' => [
                'What is system monitoring?',
                [
                    'Continuously observing system health and performance',
                    'Deleting old files automatically',
                    'Designing the user interface',
                    'Writing database queries',
                ],
                'Continuously observing system health and performance',
            ],
            'troubleshooting' => [
                'What should you check first when a computer cannot access the internet?',
                [
                    'The physical connection and network settings',
                    'The colour scheme of the desktop',
                    'The size of the hard drive',
                    'The installed fonts',
                ],
                'The physical connection and network settings',
            ],

            // ---- Networking ----
            'networking-fundamentals' => [
                'What is DHCP used for?',
                [
                    'To automatically assign IP addresses to devices on a network',
                    'To encrypt web traffic',
                    'To resolve domain names',
                    'To block malicious traffic',
                ],
                'To automatically assign IP addresses to devices on a network',
            ],
            'internet-fundamentals' => [
                'What is DNS used for?',
                [
                    'To translate domain names into IP addresses',
                    'To assign IP addresses automatically',
                    'To encrypt files',
                    'To compress images',
                ],
                'To translate domain names into IP addresses',
            ],
            'tcpip' => [
                'What is the difference between TCP and UDP?',
                [
                    'TCP is connection-oriented and reliable, while UDP is connectionless',
                    'UDP guarantees delivery, while TCP does not',
                    'They are the same protocol',
                    'TCP only works over Wi-Fi',
                ],
                'TCP is connection-oriented and reliable, while UDP is connectionless',
            ],
            'ip-addressing' => [
                'What is an IP address?',
                [
                    'A unique numeric identifier for a device on a network',
                    'A type of network cable',
                    'A web page address',
                    'A password for a router',
                ],
                'A unique numeric identifier for a device on a network',
            ],
            'routing-switching' => [
                'What is the difference between a switch and a router?',
                [
                    'A switch connects devices within a network, while a router connects different networks',
                    'A router connects devices within a network only',
                    'They perform identical functions',
                    'A switch assigns IP addresses',
                ],
                'A switch connects devices within a network, while a router connects different networks',
            ],
            'network-security' => [
                'What is the purpose of a firewall?',
                [
                    'To control and filter network traffic based on rules',
                    'To assign IP addresses',
                    'To store backups',
                    'To resolve domain names',
                ],
                'To control and filter network traffic based on rules',
            ],
            'network-troubleshooting' => [
                'Which command is commonly used to test connectivity to another host?',
                [
                    'ping',
                    'format',
                    'mkdir',
                    'chmod',
                ],
                'ping',
            ],

            // ---- Business & Systems Analysis ----
            'requirements-analysis' => [
                'What is the difference between functional and non-functional requirements?',
                [
                    'Functional requirements describe what the system does, non-functional describe how well it does it',
                    'Non-functional requirements describe what the system does',
                    'They are the same',
                    'Functional requirements are always optional',
                ],
                'Functional requirements describe what the system does, non-functional describe how well it does it',
            ],
            'stakeholder-analysis' => [
                'Who is a stakeholder in a project?',
                [
                    'Anyone affected by or interested in the project outcome',
                    'Only the software developers',
                    'Only the end users',
                    'Only the project manager',
                ],
                'Anyone affected by or interested in the project outcome',
            ],
            'business-requirements' => [
                'What is a business requirement?',
                [
                    'A high-level statement of what the business needs to achieve',
                    'A line of source code',
                    'A network configuration',
                    'A database index',
                ],
                'A high-level statement of what the business needs to achieve',
            ],
            'use-cases' => [
                'What is a use case?',
                [
                    'A description of how a user interacts with a system to achieve a goal',
                    'A type of database',
                    'A programming language',
                    'A server configuration',
                ],
                'A description of how a user interacts with a system to achieve a goal',
            ],
            'process-analysis' => [
                'What is the goal of process analysis?',
                [
                    'To understand and improve how business processes work',
                    'To design the database schema',
                    'To write the user interface',
                    'To configure the network',
                ],
                'To understand and improve how business processes work',
            ],
            'system-analysis' => [
                'What is system analysis?',
                [
                    'Studying a system to understand and improve its behaviour',
                    'Writing marketing content',
                    'Designing a logo',
                    'Installing hardware drivers',
                ],
                'Studying a system to understand and improve its behaviour',
            ],
            'requirements-engineering' => [
                'What is the difference between a business requirement and a system requirement?',
                [
                    'Business requirements describe needs, while system requirements describe how the system will meet them',
                    'System requirements are written by users only',
                    'They are identical',
                    'Business requirements are always technical',
                ],
                'Business requirements describe needs, while system requirements describe how the system will meet them',
            ],
            'uml' => [
                'What is UML used for?',
                [
                    'Modelling and documenting software system designs',
                    'Storing application data',
                    'Sending network packets',
                    'Styling web pages',
                ],
                'Modelling and documenting software system designs',
            ],
            'data-flow' => [
                'What does a data flow diagram represent?',
                [
                    'How data moves through a system and its processes',
                    'The visual design of a website',
                    'The physical layout of servers',
                    'The project budget',
                ],
                'How data moves through a system and its processes',
            ],
            'system-documentation' => [
                'Why is system documentation important?',
                [
                    'It helps teams understand, maintain and support the system',
                    'It makes the system run faster',
                    'It replaces testing',
                    'It is only needed for marketing',
                ],
                'It helps teams understand, maintain and support the system',
            ],

            // ---- Cybersecurity ----
            'cybersecurity-fundamentals' => [
                'What is phishing?',
                [
                    'A fraudulent attempt to obtain sensitive information by impersonation',
                    'A method of encrypting files',
                    'A type of firewall',
                    'A database backup',
                ],
                'A fraudulent attempt to obtain sensitive information by impersonation',
            ],
            'threats-vulnerabilities' => [
                'What is the difference between a threat and a vulnerability?',
                [
                    'A threat is a potential cause of harm, while a vulnerability is a weakness that can be exploited',
                    'A vulnerability is a potential attacker',
                    'They are the same thing',
                    'A threat only exists in hardware',
                ],
                'A threat is a potential cause of harm, while a vulnerability is a weakness that can be exploited',
            ],
            'authentication' => [
                'What is authentication?',
                [
                    'Verifying the identity of a user or system',
                    'Deciding what a user is allowed to do',
                    'Encrypting network traffic',
                    'Backing up data',
                ],
                'Verifying the identity of a user or system',
            ],
            'encryption' => [
                'What is encryption used for?',
                [
                    'Protecting data by converting it into an unreadable form',
                    'Assigning IP addresses',
                    'Compressing images',
                    'Sorting records',
                ],
                'Protecting data by converting it into an unreadable form',
            ],
            'siem' => [
                'What is SIEM?',
                [
                    'A system that collects and analyses security events in real time',
                    'A programming language',
                    'A type of network cable',
                    'A database engine',
                ],
                'A system that collects and analyses security events in real time',
            ],
            'log-analysis' => [
                'Why is log analysis important in security?',
                [
                    'It helps detect suspicious activity and investigate incidents',
                    'It speeds up the network',
                    'It replaces firewalls',
                    'It designs the user interface',
                ],
                'It helps detect suspicious activity and investigate incidents',
            ],
            'security-monitoring' => [
                'What is a security incident?',
                [
                    'An event that threatens the confidentiality, integrity or availability of systems',
                    'A routine software update',
                    'A scheduled backup',
                    'A design meeting',
                ],
                'An event that threatens the confidentiality, integrity or availability of systems',
            ],
            'incident-response' => [
                'What is incident response?',
                [
                    'The process of detecting, containing and recovering from a security incident',
                    'Installing new hardware',
                    'Writing marketing copy',
                    'Designing a database schema',
                ],
                'The process of detecting, containing and recovering from a security incident',
            ],
            'security-events' => [
                'What is the difference between an event and an incident?',
                [
                    'An event is any observable occurrence, while an incident is an event with a negative impact',
                    'An incident is any log line',
                    'They are identical',
                    'An event always causes harm',
                ],
                'An event is any observable occurrence, while an incident is an event with a negative impact',
            ],
            'vulnerability-assessment' => [
                'What is vulnerability assessment?',
                [
                    'The process of identifying and prioritising weaknesses in a system',
                    'The process of writing secure code only',
                    'A type of backup',
                    'A network cable standard',
                ],
                'The process of identifying and prioritising weaknesses in a system',
            ],
            'penetration-testing' => [
                'What is penetration testing?',
                [
                    'Authorised simulated attacks to find exploitable weaknesses',
                    'A method of encrypting data',
                    'A database migration',
                    'A user interface design technique',
                ],
                'Authorised simulated attacks to find exploitable weaknesses',
            ],
            'web-security' => [
                'Which of these is a common web security risk?',
                [
                    'Cross-site scripting (XSS)',
                    'Responsive design',
                    'Database indexing',
                    'Image compression',
                ],
                'Cross-site scripting (XSS)',
            ],
            'sql-injection' => [
                'What is SQL injection?',
                [
                    'Injecting malicious SQL through unsanitised user input',
                    'A database backup method',
                    'A type of encryption',
                    'A network protocol',
                ],
                'Injecting malicious SQL through unsanitised user input',
            ],
            'threat-modeling' => [
                'What is threat modeling?',
                [
                    'Systematically identifying potential threats and mitigations',
                    'Designing the database schema',
                    'Writing the user interface',
                    'Assigning IP addresses',
                ],
                'Systematically identifying potential threats and mitigations',
            ],

            // ---- Embedded Systems & IoT ----
            'embedded-systems' => [
                'What is an embedded system?',
                [
                    'A computer system dedicated to a specific function within a larger device',
                    'A web application',
                    'A database server',
                    'A mobile app store',
                ],
                'A computer system dedicated to a specific function within a larger device',
            ],
            'microcontrollers' => [
                'What is a microcontroller?',
                [
                    'A compact integrated circuit with a processor, memory and input/output for embedded control',
                    'A type of network switch',
                    'A database engine',
                    'A programming language',
                ],
                'A compact integrated circuit with a processor, memory and input/output for embedded control',
            ],
            'gpio' => [
                'What is GPIO?',
                [
                    'General-purpose input/output pins that can be controlled by software',
                    'A network protocol',
                    'A database query',
                    'A CSS property',
                ],
                'General-purpose input/output pins that can be controlled by software',
            ],
            'cc' => [
                'Why is C/C++ commonly used in embedded systems?',
                [
                    'It offers low-level control and predictable performance',
                    'It is the only language with loops',
                    'It cannot access hardware',
                    'It runs only in browsers',
                ],
                'It offers low-level control and predictable performance',
            ],
            'memory-management' => [
                'What is the difference between RAM and Flash memory?',
                [
                    'RAM is volatile, while Flash retains data without power',
                    'Flash is volatile, while RAM is persistent',
                    'They are identical',
                    'RAM is used only for storage',
                ],
                'RAM is volatile, while Flash retains data without power',
            ],
            'iot-fundamentals' => [
                'What is the Internet of Things (IoT)?',
                [
                    'A network of physical devices that collect and exchange data',
                    'A type of database',
                    'A frontend framework',
                    'A web browser',
                ],
                'A network of physical devices that collect and exchange data',
            ],
            'sensors-actuators' => [
                'What is the difference between a sensor and an actuator?',
                [
                    'A sensor measures a physical quantity, while an actuator performs an action',
                    'An actuator measures, while a sensor acts',
                    'They are the same device',
                    'Sensors only work offline',
                ],
                'A sensor measures a physical quantity, while an actuator performs an action',
            ],
            'mqtt' => [
                'What is MQTT?',
                [
                    'A lightweight messaging protocol for IoT devices',
                    'A database engine',
                    'A type of sensor',
                    'A CSS framework',
                ],
                'A lightweight messaging protocol for IoT devices',
            ],
            'device-communication' => [
                'How can an IoT device communicate with a server?',
                [
                    'By sending data over a network protocol such as MQTT or HTTP',
                    'Only by physical cables',
                    'Only through email',
                    'It cannot communicate',
                ],
                'By sending data over a network protocol such as MQTT or HTTP',
            ],
            'iot-security' => [
                'Why is authentication important in IoT?',
                [
                    'It ensures that only trusted devices and users can access the system',
                    'It makes devices run faster',
                    'It reduces the device size',
                    'It replaces encryption entirely',
                ],
                'It ensures that only trusted devices and users can access the system',
            ],
        ];

        foreach ($items as $skillSlug => [$question, $options, $correctAnswer]) {
            $skill = Skill::where('slug', $skillSlug)->first();

            if (! $skill) {
                continue; // Skip silently if SkillSeeder hasn't created it yet.
            }

            BaselineAssessmentItem::updateOrCreate(
                ['assessment_version' => 'v1.0', 'item_id' => $skillSlug.'-001'],
                [
                    'item_type' => 'single_choice',
                    'question_text' => $question,
                    'skill_id' => $skill->id,
                    'options' => $options,
                    'correct_answer' => $correctAnswer,
                    'scoring_rule' => null,
                    'weight' => 1.000,
                    'is_active' => true,
                ]
            );
        }
    }
}
